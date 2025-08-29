<?php

namespace App\Command;

use PHLAK\SemVer;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'import')]
class import extends Command
{
    private const string GITHUB_API_URL = 'https://api.github.com';
    private const string DOCUSAURUS_PROJECT_DIR = __DIR__ . '/../../docusaurus/phrasea';
    private const string WORKSPACE_DIR = __DIR__ . '/../workspace';
    private const string CLONE_DIRNAME = 'phrasea-documentation';
    private const string CLONE_DIR = self::WORKSPACE_DIR . '/' . self::CLONE_DIRNAME;
    private const string DOCS_DIRNAME = 'docs';
    private const string DOCS_DIR = self::CLONE_DIR . '/' . self::DOCS_DIRNAME;
    private const string DOC_REPO = 'electrautopsy/pdoc.git';
    private const string DOC_BRANCH = 'main';

    private InputInterface $input;
    private OutputInterface $output;
    private HttpClientInterface $httpClient;

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('Import documentation data from a phrasea release')
            ->addArgument('tag', InputArgument::OPTIONAL)
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'list available tags and quit (no export)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $githubToken = getenv('DOC_GITHUB_TOKEN'); // Ensure the token is set in your environment

        if (!$githubToken) {
            $this->output->writeln('Warning: GitHub token not found in environment variables DOC_GITHUB_TOKEN');
            $this->output->writeln('The builder will not be able to push the changes to the documentation repository.');
        }

        $filesystem = new Filesystem();

        $repository = 'alchemy-fr/sandbox-ci-documentation';

        $this->httpClient = HttpClient::create([
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);

        try {

            $releasesByTag = [];

            // Récupérer les releases
            $response = $this->httpClient->request('GET', self::GITHUB_API_URL . "/repos/$repository/releases");
            $releases = $response->toArray();
            foreach ($releases as $release) {
                foreach ($release['assets'] as $asset) {
                    if($asset['name'] === 'phrasea-doc.zip') {
                        $releasesByTag[$release['tag_name']] = $asset['browser_download_url'];
                    }
                }
            }

            uksort($releasesByTag, function ($a, $b) {
                $a = new Semver\Version($a);
                $b = new Semver\Version($b);
                return $a->eq($b) ? 0 : ($a->gt($b) ? -1 : 1);
            });
            if($input->getOption('list')) {
                $output->writeln("Available releases in $repository:");
                foreach($releasesByTag as $tag => $url) {
                    $output->writeln(" - $tag");
                }
                return Command::SUCCESS;
            }

            if(empty($releasesByTag)) {
                throw new IOException("No release found in $repository");
            }
            $tag = array_key_first($releasesByTag);

            if(null !== $input->getArgument('tag')) {
                $tag = $input->getArgument('tag');
                $this->output->writeln("Using tag from argument: " . $tag);
            }
            else if(null !== getenv('PHRASEA_TAG')) {
                $tag = getenv('PHRASEA_TAG');
                $this->output->writeln("Using tag from env-var PHRASEA_TAG: " . $tag);
            }
            if(!isset($releasesByTag[$tag])) {
                throw new IOException("Tag $tag not found in releases");
            }

            // download the zip, unzip and prepare the 'docs' directory for docusaurus
            $this->generateForRelease($tag, $releasesByTag[$tag]);

            // create the api documentation from the json schema
            $this->runCommand(
                ['pnpm', 'run', 'gen-api-docs', 'databox'],
                self::DOCUSAURUS_PROJECT_DIR
            );

            if($githubToken) {
                // docusaurus will build directly in the workspace/repo directory, we first clone the doc repo
                $filesystem->remove(self::WORKSPACE_DIR);
                $filesystem->mkdir(self::WORKSPACE_DIR);

                $this->runCommand(
                    ['git', 'clone', "https://{{DOC_GITHUB_TOKEN}}@github.com/" . self::DOC_REPO, self::CLONE_DIRNAME],
                    self::WORKSPACE_DIR
                );

                $semverTag = new Semver\Version($tag);
                $version = $semverTag->major . '.' . $semverTag->minor;
                if ($semverTag->patch) {
                    $version .= '.' . $semverTag->patch;
                }
                $versionDir = sprintf('%s/%s', self::DOCS_DIR, $version);
                $filesystem->mkdir($versionDir);

                $this->runCommand(
                    ['pnpm', 'build', '--out-dir', $versionDir],
                    self::DOCUSAURUS_PROJECT_DIR,
                    3600
                );

                $this->runCommand(['git', 'add', $versionDir], self::CLONE_DIR);
                $commitMessage = sprintf('update %s on %s', $version, date('c'));
                $this->runCommand(['git', 'commit', '-m', $commitMessage], self::CLONE_DIR);
                $this->runCommand(['git', 'push', '-u', 'origin', self::DOC_BRANCH], self::CLONE_DIR);

                $filesystem->remove(self::WORKSPACE_DIR);
                $this->output->writeln(sprintf('Files committed and pushed successfully to %s.', $version));
            }
            else {
                $this->output->writeln('No GitHub token provided, skipping commit and push.');
                $this->runCommand(
                    ['pnpm', 'build'],
                    self::DOCUSAURUS_PROJECT_DIR,
                    3600
                );

                $this->runCommand(
                    ['pnpm', 'run', 'serve'],
                    self::DOCUSAURUS_PROJECT_DIR,
                    0
                );
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
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

    private function generateForRelease(string $tag, string $url): void
    {
        $this->output->writeln("Downloading release: $tag from $url");

        $downloadDir = __DIR__ . '/../downloads';
        $unzipDir = Path::join($downloadDir, "phrasea-doc-$tag");
        $documentationDir = __DIR__ . '/../../docusaurus/phrasea';

        $filesystem = new Filesystem();

        if (!$filesystem->exists($downloadDir)) {
            $filesystem->mkdir($downloadDir);
        }

        $assetContent = $this->httpClient->request('GET', $url)->getContent();
        $zipFilePath = Path::join($downloadDir, "phrasea-doc-$tag.zip");
        $filesystem->dumpFile($zipFilePath, $assetContent);
        $this->output->writeln("Saved to: $zipFilePath");

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath) === true) {
            $zip->extractTo($unzipDir);
            $zip->close();
            $this->output->writeln("phrasea-doc-$tag.zip extracted to $unzipDir");
            $filesystem->remove($zipFilePath);
        }

        $target = $documentationDir . '/version.json';
        $version = [
            'tag' => $tag
        ];
        $this->output->writeln("Writing version to: " . $target);
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->compileFiles($unzipDir, $documentationDir);
    }

    private function compileFiles(string $unzipDir, string $documentationDir): void
    {
        $filesystem = new Filesystem();

        $documentationDir = rtrim($documentationDir, '/');
        $unzipDir = rtrim($unzipDir, '/');
        $generatedDocDir = $unzipDir . '/_generatedDoc';
        $generatedDocDirLen = strlen($generatedDocDir);

        // ---- first dispatch _generatedDoc files to the same sub-directories in the zip directory
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
                    if (!$filesystem->exists($d)) {
                        $filesystem->mkdir($d);
                        $this->output->writeln("Created directory: $d");
                        $createdDirs[] = $d;
                    }
                }
                elseif ($file->isFile()) {
                    $d = $unzipDir . $relativePathname;
                    if (!$filesystem->exists($d) || !file_exists($d)) {
                        $filesystem->copy($file->getPathname(), $d, true);
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
        $scan = function($subdir, $depth=0) use (&$scan, $filesystem, $unzipDir, $documentationDir, &$translations) {
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
                        $targetDir = $documentationDir . '/docs/phrasea'. $subdir;
                    }
                    else {
                        $targetDir = $documentationDir . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current/phrasea'. $subdir;
                    }
                    $this->output->writeln(sprintf("%s  copy %s to %s:%s", $tab, $file->getPathname(), $targetDir, $bn . $dotExtension));
                    $filesystem->mkdir($targetDir, 0777);
                    $filesystem->copy($file->getPathname(), $targetDir . '/' . $bn . $dotExtension, true);

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

        $filesystem->remove($unzipDir);

        // dump translations to json files
        foreach ($translations as $locale => $translation) {
            $target = $documentationDir . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            $this->output->writeln("Writing translations to: " . $target);
            if(!file_exists(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
