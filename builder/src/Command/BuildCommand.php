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
    private const string DEFAULT_PROJECT_DIR = '/srv/docusaurus/phrasea';
    private const string DEFAULT_DOC_DIR = '/srv/downloads';
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
            ->addArgument('docusaurus-project-dir', InputArgument::OPTIONAL, 'Path to docusaurus project directory', self::DEFAULT_PROJECT_DIR)
            ->addArgument('doc-dir', InputArgument::OPTIONAL, 'Path to directory where documentation versions are downloaded', self::DEFAULT_DOC_DIR)
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

        $strictMode = filter_var(getenv('DOCS_BUILD_STRICT') ?: 'false', FILTER_VALIDATE_BOOL);
        $triggerTag = getenv('PHRASEA_REFNAME') ?: null;
        $latestTag = $this->getLatestTag($versions);

        $output->writeln(sprintf('DOCS_BUILD_STRICT=%s', $strictMode ? 'true' : 'false'));
        $output->writeln(sprintf('Latest detected version=%s', $latestTag ?? 'n/a'));

        $this->filesystem->remove($projectDir . '/versioned_docs');
        $this->filesystem->remove($projectDir . '/versioned_sidebars');
        file_put_contents($projectDir . '/versions.json', json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $built = [];
        $skipped = [];

        foreach ($versions as $tag => $semver) {
            $isCritical = $strictMode || $this->isCriticalTag($tag, $triggerTag, $latestTag);

            try {
                $this->filesystem->remove($projectDir . '/docs');
                $this->filesystem->mkdir($projectDir . '/docs', 0777);

                $tagDir = $docDir.'/'.$tag;
                $mergedDir = $tagDir.'/_merged';
                if ($this->filesystem->exists($mergedDir)) {
                    $this->filesystem->remove($mergedDir);
                }

                // list specific applications
                $apps = [];
                $generatedDir = $tagDir.'/_generated';
                if (!$this->filesystem->exists($generatedDir)) {
                    $this->filesystem->mkdir($generatedDir);
                }
                $di2 = new \FilesystemIterator($generatedDir, \FilesystemIterator::SKIP_DOTS);
                foreach ($di2 as $appDir) {
                    if ($appDir->isDir()) {
                        $apps[] = $appDir->getFilename();
                    }
                }

                $this->filesystem->mirror(
                    $tagDir. '/src',
                    $mergedDir,
                );

                foreach ($apps as $app) {
                    $originDir = $generatedDir.'/'.$app.'/doc';
                    if (!$this->filesystem->exists($originDir)) {
                        continue;
                    }

                    $appDocDir = $mergedDir.'/doc/_'.$app;
                    $this->filesystem->mirror(
                        $originDir,
                        $appDocDir
                    );

                    $output->writeln(sprintf(
                        'Merged app "%s" to %s',
                        $app,
                        realpath($appDocDir)
                    ));
                }

                $this->compileFiles($tag, $projectDir, $mergedDir.'/doc', $output);

                $this->runCommand(
                    ['pnpm', 'run', 'docusaurus', 'docs:version', $semver ? ($semver->major . '.' . $semver->minor) : $tag],
                    $projectDir,
                    $output
                );

                $built[] = $tag;
                $output->writeln(sprintf('<info>Version %s built successfully.</info>', $tag));
            } catch (\Throwable $e) {
                $reason = sprintf('%s: %s', $e::class, $e->getMessage());
                if ($isCritical) {
                    $this->printBuildSummary($output, $built, $skipped, [
                        ['tag' => $tag, 'critical' => true, 'reason' => $reason],
                    ]);

                    throw $e;
                }

                $skipped[] = [
                    'tag' => $tag,
                    'critical' => false,
                    'reason' => $reason,
                ];

                $output->writeln(sprintf('<comment>Skipping non-critical version %s (%s)</comment>', $tag, $reason));
            }
        }

        $this->printBuildSummary($output, $built, $skipped, []);

        if (empty($built)) {
            throw new \RuntimeException('No version could be built successfully.');
        }

        $originalConfig = file_get_contents($projectDir . '/docusaurus.config.ts');
        $patchedConfig = str_replace('includeCurrentVersion: true', 'includeCurrentVersion: false', $originalConfig);
        file_put_contents($projectDir . '/docusaurus.config.ts', $patchedConfig);

        try {
            if (!$strictMode) {
                $this->createMissingImportedMarkdownFiles($projectDir, $output);
            } else {
                $output->writeln('<info>Prebuild placeholders disabled because DOCS_BUILD_STRICT=true.</info>');
            }

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

    private function getLatestTag(array $versions): ?string
    {
        $latestTag = null;
        foreach ($versions as $tag => $version) {
            if ($version !== null) {
                $latestTag = $tag;
            }
        }

        return $latestTag;
    }

    private function isCriticalTag(string $tag, ?string $triggerTag, ?string $latestTag): bool
    {
        return ($triggerTag !== null && $tag === $triggerTag)
            || ($latestTag !== null && $tag === $latestTag);
    }

    private function printBuildSummary(OutputInterface $output, array $built, array $skipped, array $failed): void
    {
        $output->writeln('');
        $output->writeln('<info>Build summary</info>');
        $output->writeln(sprintf('Built versions: %d', count($built)));
        foreach ($built as $tag) {
            $output->writeln(sprintf(' - built: %s', $tag));
        }

        $output->writeln(sprintf('Skipped versions: %d', count($skipped)));
        foreach ($skipped as $entry) {
            $output->writeln(sprintf(' - skipped: %s (%s)', $entry['tag'], $entry['reason']));
        }

        $output->writeln(sprintf('Failed critical versions: %d', count($failed)));
        foreach ($failed as $entry) {
            $output->writeln(sprintf(' - failed: %s (%s)', $entry['tag'], $entry['reason']));
        }
    }

    private function createMissingImportedMarkdownFiles(string $projectDir, OutputInterface $output): void
    {
        $versionedDocsDir = $projectDir . '/versioned_docs';
        if (!$this->filesystem->exists($versionedDocsDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($versionedDocsDir, \FilesystemIterator::SKIP_DOTS)
        );

        $created = [];
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['md', 'mdx'], true)) {
                continue;
            }

            $content = $this->filesystem->readFile($file->getPathname());
            if (!preg_match_all('/^\s*import\s+.+?\s+from\s+[\"\']([^\"\']+\.(?:md|mdx))[\"\'];?\s*$/m', $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $importPath) {
                // Only resolve relative imports used inside docs content.
                if (!str_starts_with($importPath, './') && !str_starts_with($importPath, '../')) {
                    continue;
                }

                $resolvedPath = $this->normalizePath(dirname($file->getPathname()) . '/' . $importPath);
                $projectRoot = rtrim($projectDir, '/');
                if (!str_starts_with($resolvedPath, $projectRoot . '/')) {
                    continue;
                }

                if ($this->filesystem->exists($resolvedPath)) {
                    continue;
                }

                $this->filesystem->mkdir(dirname($resolvedPath));
                $this->filesystem->dumpFile(
                    $resolvedPath,
                    "# Missing generated documentation\n\nThis placeholder was auto-generated during prebuild to satisfy a legacy relative import.\n"
                );
                $created[] = $resolvedPath;
            }
        }

        if (!empty($created)) {
            $output->writeln(sprintf('<comment>Prebuild: created %d placeholder file(s) for missing MD/MDX imports.</comment>', count($created)));
            foreach ($created as $path) {
                $output->writeln(sprintf('<comment> - %s</comment>', $path));
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (!empty($normalized)) {
                    array_pop($normalized);
                }
                continue;
            }

            $normalized[] = $part;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $normalized);
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
            if (!$this->filesystem->exists($projectDir . '/docs/_' . $app.'/_schema.json')) {
                continue;
            }

            $this->runCommand(
                ['pnpm', 'run', 'gen-api-docs', $app],
                $projectDir,
                $output
            );

            $docDir = $projectDir . '/docs/' . $app . '_api';
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
