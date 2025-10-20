<?php

namespace App\Command;

use PHLAK\SemVer;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'build')]
class build extends Command
{
    private const string DOCUSAURUS_PROJECT_DIR = __DIR__ . '/../../docusaurus/phrasea';
    private const string DOWNLOAD_DIR = __DIR__ . '/../downloads';


    private InputInterface $input;
    private OutputInterface $output;
    private Filesystem $filesystem;

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('Build documentation with data fetched from phrasea image');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->filesystem = new Filesystem();

        $output->writeln('-------- running php build -------');
        foreach(['PHRASEA_REFNAME', 'PHRASEA_REFTYPE', 'PHRASEA_DATETIME'] as $env) {
            $output->writeln($env . '=' . getenv($env));
        }

        $versions = [];
        foreach(new \FilesystemIterator(self::DOWNLOAD_DIR, \FilesystemIterator::SKIP_DOTS) as $versionDir) {
            if (!$versionDir->isDir()) {
                continue;
            }
            $tag = $versionDir->getFilename();
            $output->writeln('Download dir contains: ' . $tag);
            try {
                $versions[$tag] = new Semver\Version($tag);
            }
            catch (SemVer\Exceptions\InvalidVersionException $e) {
                $versions[$tag] = null;
            }
        }
        uasort($versions, function ($a, $b) {
            if($a === null || $b === null) {
                return $a === $b ? 0 : ($a === null ? -1 : 1);
            }
            return $a->eq($b) ? 0 : ($a->gt($b) ? 1 : -1);
        });

        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/versioned_docs');
        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/versioned_sidebars');
        file_put_contents(self::DOCUSAURUS_PROJECT_DIR . '/versions.json', json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($versions as $tag => $semver) {

            $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/docs/doc');
            $this->filesystem->remove(self::DOWNLOAD_DIR . '/' . $tag . '/docmerged');
            // list specific applications (directories at /, except /doc which is the "general" documentation)
            $apps = [];
            $di2 = new \FilesystemIterator(self::DOWNLOAD_DIR . '/' . $tag, \FilesystemIterator::SKIP_DOTS);
            foreach ($di2 as $appDir) {
                if (!$appDir->isDir() || $appDir->getFilename() === 'doc' || $appDir->getFilename() === 'docmerged') {
                    continue;
                }
                $apps[] = $appDir->getFilename();
                $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/docs/' . $appDir->getFilename());
            }

            $this->filesystem->mirror(
                self::DOWNLOAD_DIR . '/' . $tag . '/doc/',
                self::DOWNLOAD_DIR . '/' . $tag . '/docmerged/'
            );

            foreach ($apps as $app) {
                $this->filesystem->mirror(
                    self::DOWNLOAD_DIR . '/' . $tag . '/' . $app . '/doc',
                    self::DOWNLOAD_DIR . '/' . $tag . '/docmerged' . '/_' . $app . '/doc'
                );
                $this->output->writeln(sprintf('Merged app "%s" to %s',
                    $app,
                    realpath(self::DOWNLOAD_DIR . '/' . $tag . '/docmerged' . '/_' . $app . '/doc')
                ));
            }

            $this->compileFiles(self::DOWNLOAD_DIR . '/' . $tag . '/docmerged', $tag);

            // version
            $this->runCommand(
                ['pnpm', 'run', 'docusaurus', 'docs:version', $semver ? ($semver->major . '.' . $semver->minor) : $tag],
                self::DOCUSAURUS_PROJECT_DIR
            );
        }

        $orgConfig = file_get_contents(self::DOCUSAURUS_PROJECT_DIR . '/docusaurus.config.ts');
        $patchedConfig = str_replace('includeCurrentVersion: true', 'includeCurrentVersion: false', $orgConfig);
        file_put_contents(self::DOCUSAURUS_PROJECT_DIR . '/docusaurus.config.ts', $patchedConfig);

        $this->filesystem->mkdir(self::DOCUSAURUS_PROJECT_DIR . '/build');
        $process = $this->runCommand(
            ['pnpm', 'run', 'build'],
            self::DOCUSAURUS_PROJECT_DIR,
            3600
        );
        file_put_contents(
            self::DOCUSAURUS_PROJECT_DIR . '/build/build.html',
            '<html><pre>'.$process->getOutput().'</pre></html>'
        );
        file_put_contents(
            self::DOCUSAURUS_PROJECT_DIR . '/build/build-error.html',
            '<html><pre>'.$process->getErrorOutput().'</pre></html>'
        );
        file_put_contents(
            self::DOCUSAURUS_PROJECT_DIR . '/build/version.html',
            sprintf(
                "<html><pre>REFNAME:%s\nREFTYPE:%s\nDATETIME:%s</pre></html>",
                getenv('PHRASEA_REFNAME'),
                getenv('PHRASEA_REFTYPE'),
                getenv('PHRASEA_DATETIME')
            )
        );

        file_put_contents(self::DOCUSAURUS_PROJECT_DIR . '/docusaurus.config.ts', $orgConfig);

        return Command::SUCCESS;
    }

    private function runCommand(array $command, string $workingDir, int $timeout=60): Process
    {
        $m = join(' ', array_map(
            fn($m) => escapeshellcmd($m) === $m ? $m : escapeshellarg($m),
            $command
        ));
        $this->output->writeln('<info>Running command:</info> ' . $m);

        $command = array_map(function($c) {
            return preg_replace_callback(
                '|\{\{(\w+)}}|',
                fn($m) => getenv($m[1]), $c);
        }, $command);

        $process = new Process($command, $workingDir);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);

        $process->run(function ($type, $buffer): void {
            $this->output->write('.');
        });
        $this->output->writeln('');

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function compileFiles(string $unzipDir, string $version): void
    {
        // ---- dispatch the files in the documentation directory
        $translations = [];
        $scan = function($subdir, $depth=0) use (&$scan, $unzipDir, &$translations) {
            $tab = str_repeat('  ', $depth);
            $scandir =  $unzipDir . $subdir;

            $this->output->writeln(sprintf("%sScanning .%s",
                $tab,
                $subdir
            ));

            /** @var SplFileInfo $file */
            foreach (new \FilesystemIterator($scandir, \FilesystemIterator::SKIP_DOTS) as $file) {

                if ($file->isFile()) {
                    if($file->getFilename() === '_locales.yml' || $file->getFilename() === '.gitkeep') {
                        continue;
                    }

                    // remove extension
                    $dotExtension = $file->getExtension() ? ('.' . $file->getExtension()) : '';
                    $bn = $file->getBasename($dotExtension);
                    $locale = '_';
                    // find locale ?
                    $matches = [];
                    if(preg_match('/(.*)\.(\w*)/', $bn, $matches) && count($matches) === 3) {
                        $bn = $matches[1];
                        $locale = $matches[2];
                        if($locale === 'en') {
                            $locale = '_'; // no locale for en
                        }
                    }

                    if($locale === '_') {
                        $subTargetDir = 'docs'. $subdir;
                    }
                    else {
                        $subTargetDir = 'i18n/' . $locale . '/docusaurus-plugin-content-docs/current'. $subdir;
                    }
                    $this->output->writeln(sprintf("%s  copy %s to %s/%s",
                        $tab,
                        $file->getFilename(),
                        $subTargetDir,
                        $bn . $dotExtension
                    ));
                    $targetDir = self::DOCUSAURUS_PROJECT_DIR . '/' . $subTargetDir;
                    $this->filesystem->mkdir($targetDir, 0777);
                    $this->filesystem->copy($file->getPathname(), $targetDir . '/' . $bn . $dotExtension, true);

                } elseif ($file->isDir()) {
                    if(file_exists($file->getPathname() . '/_locales.yml')) {
                        foreach (Yaml::parse(file_get_contents($file->getPathname() . '/_locales.yml')) as $locale => $translation) {
                            if(!isset($translations[$locale])) {
                                $translations[$locale] = [];
                            }
                            $t = [
                                'message' => $translation,
                                'description' => 'Sidebar title for directory ' . $file->getPathname(),
                            ];
                            $translations[$locale]['sidebar.techdocSidebar.category.'.$file->getFilename()] = $t;
                            $translations[$locale]['sidebar.userdocSidebar.category.'.$file->getFilename()] = $t;
                        }
                    }

                    $scan($subdir . '/' . $file->getFilename(), $depth + 1);
                }
            }
        };

        $this->output->writeln(sprintf("Compiling %s to %s", realpath($unzipDir), realpath(self::DOCUSAURUS_PROJECT_DIR)));
        $scan('');

        // $this->filesystem->remove($unzipDir);

        // dump version
        $target = self::DOCUSAURUS_PROJECT_DIR . '/version.json';
        $version = [
            'refname' => getenv('PHRASEA_REFNAME'),
            'reftype' => getenv('PHRASEA_REFTYPE'),
            'datetime' => getenv('PHRASEA_DATETIME'),
        ];
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->output->writeln("Wrote version to: " . realpath($target));

        // dump translations to json files
        foreach ($translations as $locale => $translation) {
            $target = self::DOCUSAURUS_PROJECT_DIR . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            if(!file_exists(dirname($target))) {
                $this->filesystem->mkdir(dirname($target));
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->output->writeln("Wrote translations to: " . realpath($target));
        }

        // create the api documentation from the json schema
        $this->runCommand(
            ['pnpm', 'run', 'gen-api-docs', 'databox'],
            self::DOCUSAURUS_PROJECT_DIR
        );

        // ========== fix for api auto-generated sidebar (https://github.com/facebook/docusaurus/discussions/11458)
        //            we add a "key" to each item
        $this->output->writeln("Patching sidebar.ts to add keys.");
        $this->filesystem->copy(
            self::DOCUSAURUS_PROJECT_DIR . '/docs/databox_api/sidebar.ts',
            self::DOCUSAURUS_PROJECT_DIR . '/docs/databox_api/sidebar-bkp.ts',
            true
        );
        $this->filesystem->dumpFile(
            self::DOCUSAURUS_PROJECT_DIR . '/docs/databox_api/sidebar.ts',
            preg_replace(
                "/(( *)id: (.*),)/m",
                "$1\n$2key: $3,",
                $this->filesystem->readFile(self::DOCUSAURUS_PROJECT_DIR . '/docs/databox_api/sidebar.ts')
            )
        );
    }
}
