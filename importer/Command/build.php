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
    private const string GITHUB_URL = 'https://github.com';
    private const string GITHUB_API_URL = 'https://api.github.com';
    private const string PHRASEA_REPO = 'alchemy-fr/sandbox-ci-documentation';
    private const string DOC_REPO = 'alchemy-fr/phrasea-documentation';
    private const string DOC_BRANCH = 'main';

    private const string DOCUSAURUS_PROJECT_DIR = __DIR__ . '/../../docusaurus/phrasea';
    private const string DOWNLOAD_DIR = __DIR__ . '/../downloads';
    private const string WORKSPACE_DIR = __DIR__ . '/../workspace';
    private const string CLONE_DIRNAME = 'phrasea-documentation';
    private const string CLONE_DIR = self::WORKSPACE_DIR . '/' . self::CLONE_DIRNAME;
    private const string DOCS_DIRNAME = 'docs';
    private const string DOCS_DIR = self::CLONE_DIR . '/' . self::DOCS_DIRNAME;

    private InputInterface $input;
    private OutputInterface $output;

//    private string $githubToken;
    private string $phrasea_repo;
    private string $doc_repo;
    private string $doc_branch;
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

        $this->phrasea_repo = getenv('PHRASEA_REPO') ?: self::PHRASEA_REPO;
        $this->doc_repo     = getenv('DOC_REPO') ?: self::DOC_REPO;
        $this->doc_branch   = getenv('DOC_BRANCH') ?: self::DOC_BRANCH;

        $this->filesystem = new Filesystem();

        $output->writeln('------------------- running php -------');
        foreach(['PHRASEA_TAG', 'PHRASEA_REF'] as $env) {
            $output->writeln($env . '=' . getenv($env));
        }

$this->filesystem->mkdir(__DIR__ . '/../build');
//$this->filesystem->mirror(__DIR__ . '/../downloads/', __DIR__ . '/../build/');
        $html = "<html><body><h1>Download dir contains:</h1>\n<ul>\n";
        $di = new \DirectoryIterator(self::DOWNLOAD_DIR);
        foreach ($di as $file) {
            if ($file->isDot() || !$file->isDir()) {
                continue;
            }
            $output->writeln('Download dir contains: ' . $file->getFilename());
            $html .= '<li>' . $file->getFilename() . "</li>\n";
        }
        $html .= "</ul></body></html>\n";
        file_put_contents(__DIR__ . '/../build/index.html', $html);
return Command::SUCCESS;


        try {
            if (getenv('PHRASEA_TAG')) {
                return $this->exportByTag(getenv('PHRASEA_TAG'));
            }
        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function exportByTag(string $tag): int
    {
        $unzipDir = self::DOWNLOAD_DIR . "/doc";

        $this->compileFiles($unzipDir, $tag);

        try {
            $semverTag = new Semver\Version($tag);
            $version = $semverTag->major . '.' . $semverTag->minor;
        }
        catch (SemVer\Exceptions\InvalidVersionException $e) {
            $version = $tag;
        }

        return $this->generateAndPush($version);
    }

    private function generateAndPush(string $version): int
    {
        try {
            // create the api documentation from the json schema
            $this->runCommand(
                ['pnpm', 'run', 'gen-api-docs', 'databox'],
                self::DOCUSAURUS_PROJECT_DIR
            );

            $this->runCommand(
                ['pnpm', 'build'],
                self::DOCUSAURUS_PROJECT_DIR,
                3600
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
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
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->output->writeln($process->getOutput());
    }

    private function compileFiles(string $unzipDir, string $version): void
    {
        $unzipDir = rtrim($unzipDir, '/');
        $generatedDocDir = $unzipDir . '/_generatedDoc';
        $generatedDocDirLen = strlen($generatedDocDir);

        // ---- first dispatch _generatedDoc files to the same subdirectories in the zip directory
        //      = move files one subdir up
        $createdDirs = [];
        $copiedFiles = [];
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedDocDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relativePathname = substr($file->getPathname(), $generatedDocDirLen);

                $this->output->writeln($relativePathname . ' => ' . $file->getFilename());
                if ($file->isDir()) {
                    $d = $unzipDir . $relativePathname;
                    if (!$this->filesystem->exists($d)) {
                        $this->filesystem->mkdir($d);
                        $this->output->writeln("Created directory: $d");
                        $createdDirs[] = $d;
                    }
                }
                elseif ($file->isFile()) {
                    $d = $unzipDir . $relativePathname;
                    if (!$this->filesystem->exists($d) || !file_exists($d)) {
                        $this->filesystem->copy($file->getPathname(), $d, true);
                        $this->output->writeln("Copied file: " . $file->getPathname() . " to " . $d);
                        $copiedFiles[] = $d;
                    }
                    else {
                        $this->output->writeln("File already exists, skipping: " . $d);
                    }
                }
            }
        }
        catch (\Exception $e) {
            // _generatedDoc directory can not exist
        }

        $this->output->writeln("");

        // ---- then dispatch the files in the documentation directory
        $translations = [];
        $scan = function($subdir, $depth=0) use (&$scan, $unzipDir, &$translations) {
            $tab = str_repeat('  ', $depth);
            $scandir =  $unzipDir . $subdir;
            $this->output->writeln(sprintf("%sScanning %s", $tab, $scandir));
            $iterator = new \DirectoryIterator($scandir);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {

                if ($file->isDot()) {
                    continue; // skip . and ..
                }

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

        $this->filesystem->remove($unzipDir);

        // dump translations to json files
        foreach ($translations as $locale => $translation) {
            $target = self::DOCUSAURUS_PROJECT_DIR . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            $this->output->writeln("Writing translations to: " . $target);
            if(!file_exists(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // dump version
        $target = self::DOCUSAURUS_PROJECT_DIR . '/version.json';
        $version = [
            'tag' => $version
        ];
        $this->output->writeln("Writing version to: " . $target);
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
