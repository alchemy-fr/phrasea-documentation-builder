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

        $output->writeln('------------------- running php -------');
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
        // move master to the end, so it will end-up as "current" (named "Next" in docusaurus)
        if(array_key_exists('master', $versions)) {
            $master = $versions['master'];
            unset($versions['master']);
            $versions['master'] = $master;
        }
        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/versioned_docs');
        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/versioned_sidebars');
        file_put_contents(self::DOCUSAURUS_PROJECT_DIR . '/versions.json', json_encode(['0.0.0'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $n = count($versions);
        foreach ($versions as $tag => $semver) {

            $this->filesystem->mirror(self::DOWNLOAD_DIR . '/' . $tag . '/doc/', self::DOWNLOAD_DIR . '/' . $tag . '/docmerged/');

            // list specific applications (directories at /, except /doc which is the "general" documentation)
            $apps = [];
            $di2 = new \FilesystemIterator(self::DOWNLOAD_DIR . '/' . $tag, \FilesystemIterator::SKIP_DOTS);
            foreach ($di2 as $appDir) {
                if (!$appDir->isDir() || $appDir->getFilename() === 'doc' || $appDir->getFilename() === 'docmerged') {
                    continue;
                }
                $apps[] = $appDir->getFilename();
            }

            $this->fusionAppsToGeneral($tag, $apps);
            $this->compileFiles(self::DOWNLOAD_DIR . '/' . $tag . '/docmerged', $tag);

            if ($n > 1) {
                // version
                $this->runCommand(
                    ['pnpm', 'run', 'docusaurus', 'docs:version', $semver ? ($semver->major . '.' . $semver->minor) : $tag],
                    self::DOCUSAURUS_PROJECT_DIR
                );
            }
            $n--;
        }

        $v = json_decode(file_get_contents(self::DOCUSAURUS_PROJECT_DIR . '/versions.json'), true, 512, JSON_THROW_ON_ERROR);
        $v = array_filter($v, fn($ver) => $ver !== '0.0.0');
        file_put_contents(self::DOCUSAURUS_PROJECT_DIR . '/versions.json', json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    private function fusionAppsToGeneral(string $tag, array $apps): void
    {
        foreach ($apps as $app) {
            $this->output->writeln("Merging app $app into general doc for tag $tag");

            $src = self::DOWNLOAD_DIR . '/' . $tag . '/' . $app . '/doc';
            $dst = self::DOWNLOAD_DIR . '/' . $tag . '/docmerged';

            $this->filesystem->mirror($src, $dst);
        }
    }

    private function runCommand(array $command, string $workingDir, int $timeout=60): void
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
//            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function compileFiles(string $unzipDir, string $version): void
    {
        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/docs/phrasea');
        $this->filesystem->remove(self::DOCUSAURUS_PROJECT_DIR . '/docs/databox-api');

        // ---- dispatch the files in the documentation directory
        $translations = [];
        $scan = function($subdir, $depth=0) use (&$scan, $unzipDir, &$translations) {
            $tab = str_repeat('  ', $depth);
            $scandir =  $unzipDir . $subdir;
            $this->output->writeln(sprintf("%sScanning %s", $tab, $scandir));

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
                        $targetDir = self::DOCUSAURUS_PROJECT_DIR . '/docs/phrasea'. $subdir;
                    }
                    else {
                        $targetDir = self::DOCUSAURUS_PROJECT_DIR . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current/phrasea'. $subdir;
                    }
                    $this->output->writeln(sprintf("%s  copy %s to %s:%s", $tab, $file->getPathname(), $targetDir, $bn . $dotExtension));
                    $this->filesystem->mkdir($targetDir, 0777);
                    $this->filesystem->copy($file->getPathname(), $targetDir . '/' . $bn . $dotExtension, true);

                } elseif ($file->isDir()) {
                    if(file_exists($file->getPathname() . '/_locales.yml')) {
                        foreach (Yaml::parse(file_get_contents($file->getPathname() . '/_locales.yml')) as $locale => $translation) {
                            if(!isset($translations[$locale])) {
                                $translations[$locale] = [];
                            }
                            $translations[$locale]['sidebar.tutorialSidebar.category.'.$file->getFilename()] = [
                                'message' => $translation,
                                'description' => 'Sidebar title for ' . $file->getPathname(),
                            ];
                        }
                    }

                    $scan($subdir . '/' . $file->getFilename(), $depth + 1);
                }
            }
        };

        $scan('');

        // $this->filesystem->remove($unzipDir);

        // dump version
        $target = self::DOCUSAURUS_PROJECT_DIR . '/version.json';
        $version = [
            'refname' => getenv('PHRASEA_REFNAME'),
            'reftype' => getenv('PHRASEA_REFTYPE'),
            'datetime' => getenv('PHRASEA_DATETIME'),
        ];
        $this->output->writeln("Writing version to: " . $target);
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // dump translations to json files
        foreach ($translations as $locale => $translation) {
            $target = self::DOCUSAURUS_PROJECT_DIR . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            $this->output->writeln("Writing translations to: " . $target);
            if(!file_exists(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // create the api documentation from the json schema
        $this->runCommand(
            ['pnpm', 'run', 'gen-api-docs', 'databox'],
            self::DOCUSAURUS_PROJECT_DIR
        );
    }
}
