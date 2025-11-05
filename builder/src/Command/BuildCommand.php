<?php

namespace App\Command;

use PHLAK\SemVer;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'build')]
class BuildCommand extends Command
{
    private const string NO_LOCALE = '_';
    private Filesystem $filesystem;

    public function __construct(?string $name = null, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
        parent::__construct($name);
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('docusaurus-project-dir', InputArgument::REQUIRED, 'Path to docusaurus project directory')
            ->addArgument('doc-dir', InputArgument::REQUIRED, 'Path to directory where documentation versions are downloaded')
            ->setDescription('Build documentation with data fetched from phrasea image');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectDir = $input->getArgument('docusaurus-project-dir');
        $docDir = $input->getArgument('doc-dir');

        $output->writeln('-------- running php build -------');
        foreach (['PHRASEA_REFNAME', 'PHRASEA_REFTYPE', 'PHRASEA_DATETIME'] as $env) {
            $output->writeln($env . '=' . getenv($env));
        }

        $versions = [];
        foreach (new \FilesystemIterator($docDir, \FilesystemIterator::SKIP_DOTS) as $versionDir) {
            if (!$versionDir->isDir()) {
                continue;
            }
            $tag = $versionDir->getFilename();
            $output->writeln('Download dir contains: ' . $tag);
            try {
                $versions[$tag] = new Semver\Version($tag);
            } catch (SemVer\Exceptions\InvalidVersionException $e) {
                $versions[$tag] = null;
            }
        }
        uasort($versions, function ($a, $b) {
            if ($a === null || $b === null) {
                return $a === $b ? 0 : ($a === null ? -1 : 1);
            }
            return $a->eq($b) ? 0 : ($a->gt($b) ? 1 : -1);
        });

        $this->filesystem->remove($projectDir . '/docs');
        $this->filesystem->remove($projectDir . '/versioned_docs');
        $this->filesystem->remove($projectDir . '/versioned_sidebars');
        file_put_contents($projectDir . '/versions.json', json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($versions as $tag => $semver) {
            $this->filesystem->remove($docDir . '/' . $tag . '/_merged');

            // list specific applications
            $apps = [];
            $di2 = new \FilesystemIterator($docDir . '/' . $tag . '/_generated', \FilesystemIterator::SKIP_DOTS);
            foreach ($di2 as $appDir) {
                if ($appDir->isDir()) {
                    $apps[] = $appDir->getFilename();
                }
            }

            $this->filesystem->mirror(
                $docDir . '/' . $tag . '/src/',
                $docDir . '/' . $tag . '/_merged/'
            );

            foreach ($apps as $app) {
                $this->filesystem->mirror(
                    $docDir . '/' . $tag . '/_generated/' . $app . '/doc/',
                    $docDir . '/' . $tag . '/_merged/doc/_' . $app
                );
                $output->writeln(sprintf(
                    'Merged app "%s" to %s',
                    $app,
                    realpath($docDir . '/' . $tag . '/_merged/doc/_' . $app)
                ));
            }

            $this->compileFiles($tag, $projectDir, $docDir . '/' . $tag . '/_merged/doc', $output);

            $this->runCommand(
                ['pnpm', 'run', 'docusaurus', 'docs:version', $semver ? ($semver->major . '.' . $semver->minor) : $tag],
                $projectDir,
                $output
            );
        }

        $originalConfig = file_get_contents($projectDir . '/docusaurus.config.ts');
        $patchedConfig = str_replace('includeCurrentVersion: true', 'includeCurrentVersion: false', $originalConfig);
        file_put_contents($projectDir . '/docusaurus.config.ts', $patchedConfig);

        try {
            $this->filesystem->mkdir($projectDir . '/build');
            $process = $this->runCommand(
                ['pnpm', 'run', 'build'],
                $projectDir,
                $output,
                3600
            );
            file_put_contents(
                $projectDir . '/build/build.html',
                '<html lang="en"><pre>'.$process->getOutput().'</pre></html>'
            );
            file_put_contents(
                $projectDir . '/build/build-error.html',
                '<html lang="en"><pre>'.$process->getErrorOutput().'</pre></html>'
            );
            file_put_contents(
                $projectDir . '/build/version.html',
                sprintf(
                    '<html lang="en"><pre>REFNAME:%s\nREFTYPE:%s\nDATETIME:%s</pre></html>',
                    getenv('PHRASEA_REFNAME'),
                    getenv('PHRASEA_REFTYPE'),
                    getenv('PHRASEA_DATETIME')
                )
            );

            file_put_contents($projectDir . '/docusaurus.config.ts', $originalConfig);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Restore config to prevent side effects on next runs
            file_put_contents($projectDir . '/docusaurus.config.ts', $originalConfig);

            throw $e;
        }
    }

    private function runCommand(array $command, string $workingDir, OutputInterface $output, int $timeout = 60): Process
    {
        $output->writeln('<info>Running command:</info> ' . implode(' ', $command));
        $process = new Process($command, $workingDir);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);

        $process->run(function () use ($output): void {
            $output->write('.');
        });
        $output->writeln('');
        $errorOutput = $process->getErrorOutput();
        if (trim($errorOutput)) {
            $output->writeln(sprintf('<error>%s</error>', $errorOutput));
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function compileFiles(string $tag, string $projectDir, string $dir, OutputInterface $output): void
    {
        // ---- dispatch the files in the documentation directory
        $translations = [];
        $scan = function (string $subDir, int $depth = 0) use (&$scan, $dir, &$translations, $output, $projectDir, $tag) {
            $tab = str_repeat('  ', $depth);
            $scanDir =  $dir . $subDir;

            $output->writeln(sprintf(
                "%sScanning .%s",
                $tab,
                $subDir
            ));

            /** @var SplFileInfo $file */
            foreach (new \FilesystemIterator($scanDir, \FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file->isFile()) {
                    if ($file->getFilename() === '_locales.yml' || $file->getFilename() === '.gitkeep') {
                        continue;
                    }

                    // remove extension
                    $dotExtension = $file->getExtension() ? ('.' . $file->getExtension()) : '';
                    $bn = $file->getBasename($dotExtension);
                    $locale = self::NO_LOCALE;
                    // find locale?
                    $matches = [];
                    if (preg_match('/(.*)\.(\w*)/', $bn, $matches) && count($matches) === 3) {
                        $bn = $matches[1];
                        $locale = $matches[2];
                        if ($locale === 'en') {
                            $locale = self::NO_LOCALE; // no locale for en
                        }
                    }

                    if ($locale === self::NO_LOCALE) {
                        $subTargetDir = 'docs'. $subDir;
                    } else {
                        $subTargetDir = 'i18n/' . $locale . '/docusaurus-plugin-content-docs/current'. $subDir;
                    }
                    $output->writeln(sprintf(
                        "%s  copy %s to %s/%s",
                        $tab,
                        $file->getFilename(),
                        $subTargetDir,
                        $bn . $dotExtension
                    ));
                    $targetDir = $projectDir . '/' . $subTargetDir;
                    $this->filesystem->mkdir($targetDir, 0777);
                    $destination = $targetDir.'/'.$bn.$dotExtension;

                    $this->filesystem->dumpFile(
                        $destination,
                        preg_replace(
                            "#\(@phrasea-repo/#",
                            sprintf('(https://github.com/alchemy-fr/phrasea/blob/%s/', $tag),
                            $this->filesystem->readFile($file->getPathname())
                        )
                    );
                } elseif ($file->isDir()) {
                    if (file_exists($file->getPathname() . '/_locales.yml')) {
                        foreach (Yaml::parse(file_get_contents($file->getPathname() . '/_locales.yml')) as $locale => $translation) {
                            if (!isset($translations[$locale])) {
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

                    $scan($subDir . '/' . $file->getFilename(), $depth + 1);
                }
            }
        };

        $output->writeln(sprintf("Compiling %s to %s", realpath($dir), realpath($projectDir)));
        $scan('');

        // Dump version
        $target = $projectDir . '/version.json';
        $version = [
            'refname' => getenv('PHRASEA_REFNAME'),
            'reftype' => getenv('PHRASEA_REFTYPE'),
            'datetime' => getenv('PHRASEA_DATETIME'),
        ];
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $output->writeln("Wrote version to: " . realpath($target));

        // Dump translations to json files
        foreach ($translations as $locale => $translation) {
            $target = $projectDir . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            if (!file_exists(dirname($target))) {
                $this->filesystem->mkdir(dirname($target));
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $output->writeln('Wrote translations to: ' . realpath($target));
        }

        $apps = ['databox', 'expose', 'uploader'];

        // Create the API documentation from the JSON schema
        foreach ($apps as $app) {
            $docDir = $projectDir . '/docs/' . $app . '_api';

            $this->runCommand(
                ['pnpm', 'run', 'gen-api-docs', $app],
                $projectDir,
                $output
            );

            // Fix API auto-generated sidebar (https://github.com/facebook/docusaurus/discussions/11458)
            $sideBarSrc = $docDir.'/sidebar.ts';
            $output->writeln(sprintf('Adding key props to %s', $sideBarSrc));

            $this->filesystem->copy(
                $sideBarSrc,
                $docDir . '/sidebar-bkp.ts',
                true
            );
            $this->filesystem->dumpFile(
                $sideBarSrc,
                preg_replace_callback(
                    '/\Wid:\s*(["\'])((?:\\\1|(?:(?!\1)).)*)(\1)/m',
                    function (array $regs) use ($app) {
                        return sprintf(
                            'id:%1$s%2$s%3$s, key:%1$s%4$s_%2$s_%3$s',
                            $regs[1],
                            $regs[2],
                            $regs[3],
                            $app,
                        );
                    },
                    preg_replace_callback(
                        '/type:\s*"category"\s*,\s*label:\s*(["\'])((?:\\\1|(?:(?!\1)).)*)(\1)/m',
                        function (array $regs) use ($app) {
                            return sprintf(
                                'type: "category", label:%1$s%2$s%3$s, key:%1$s%4$s_%2$s_%3$s',
                                $regs[1],
                                $regs[2],
                                $regs[3],
                                $app,
                            );
                        },
                        $this->filesystem->readFile($sideBarSrc)
                    )
                )
            );
        }
    }
}
