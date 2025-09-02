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
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'import')]
class import extends Command
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
    private HttpClientInterface $httpClient;
    private string $githubToken;
    private string $phrasea_repo;
    private string $doc_repo;
    private string $doc_branch;
    private Filesystem $filesystem;

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('Import documentation data from a phrasea release')
            ->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'release (tag) to import')
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'branch to import')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'list available tags and quit (no export)')
            ->setHelp("--tag and --branch are mutually exclusive options.\n"
                       . "If none is provided env-vars will be used:\n"
                       . "  - PHRASEA_TAG for --tag\n"
                       . "  - PHRASEA_BRANCH for --branch\n"
                       . "If none is provided the latest release will be used.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->phrasea_repo = getenv('PHRASEA_REPO') ?: self::PHRASEA_REPO;
        $this->doc_repo     = getenv('DOC_REPO') ?: self::DOC_REPO;
        $this->doc_branch   = getenv('DOC_BRANCH') ?: self::DOC_BRANCH;
        $this->githubToken  = getenv('DOC_GITHUB_TOKEN');
        $this->filesystem = new Filesystem();

        if (!$this->githubToken) {
            $this->output->writeln('Warning: GitHub token not found in env-var DOC_GITHUB_TOKEN');
            $this->output->writeln('The builder will not be able to push the changes to the documentation repository.');
        }

        $this->httpClient = HttpClient::create([
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);

        try {

            $releasesByTag = [];
            $branchesByName = [];

            // get releases
            $response = $this->httpClient->request('GET', self::GITHUB_API_URL . "/repos/".$this->phrasea_repo."/releases");
            $releases = $response->toArray();
            foreach ($releases as $release) {
                foreach ($release['assets'] as $asset) {
                    if($asset['name'] === 'phrasea-doc.zip') {
                        $releasesByTag[$release['tag_name']] = $asset['browser_download_url'];
                    }
                }
            }

            // get branches
            $response = $this->httpClient->request('GET', self::GITHUB_API_URL . "/repos/".$this->phrasea_repo."/branches");
            $branches = $response->toArray();
            foreach ($branches as $branch) {
                $branchesByName[$branch['name']] = $branch['commit']['url'];
            }

            uksort($releasesByTag, function ($a, $b) {
                $a = new Semver\Version($a);
                $b = new Semver\Version($b);
                return $a->eq($b) ? 0 : ($a->gt($b) ? -1 : 1);
            });
            if($input->getOption('list')) {
                $output->writeln("Available releases in ".$this->phrasea_repo.":");
                foreach($releasesByTag as $tag => $url) {
                    $output->writeln(" - $tag");
                }
                $output->writeln("Available branches in ".$this->phrasea_repo.":");
                foreach($branchesByName as $name => $url) {
                    $output->writeln(" - $name");
                }
                return Command::SUCCESS;
            }

            $this->filesystem->remove(self::DOWNLOAD_DIR);
            $this->filesystem->mkdir(self::DOWNLOAD_DIR);

            if($input->getOption('tag') && $input->getOption('branch')) {
                $this->output->writeln("--tag and --branch are mutually exclusive");
                return Command::FAILURE;
            }
            if($input->getOption('tag')) {
                return $this->exportByTag($releasesByTag, $input->getOption('tag'));
            }
            if($input->getOption('branch')) {
                return $this->exportByBranch($branchesByName, $input->getOption('branch'));
            }

            if(getenv('PHRASEA_TAG') && getenv('PHRASEA_BRANCH')) {
                $this->output->writeln("env(PHRASEA_TAG) and env(PHRASEA_BRANCH) are mutually exclusive");
                return Command::FAILURE;
            }
            if(getenv('PHRASEA_TAG')) {
                return $this->exportByTag($releasesByTag, getenv('PHRASEA_TAG'));
            }
            if(getenv('PHRASEA_BRANCH')) {
                return $this->exportByBranch($branchesByName, getenv('PHRASEA_BRANCH'));
            }

            // fallback : use latest release
            if(empty($releasesByTag)) {
                $this->output->writeln("No release found in ".$this->phrasea_repo."");
                return Command::FAILURE;
            }

            return $this->exportByTag($releasesByTag, array_key_first($releasesByTag));

        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function exportByTag(array $releasesByTag, string $tag): int
    {
        if (!isset($releasesByTag[$tag])) {
            $this->output->writeln("Tag $tag not found in releases");

            return Command::FAILURE;
        }

        // download the zip, unzip and prepare the 'docs' directory for docusaurus
        $url = $releasesByTag[$tag];
        $this->output->writeln("Downloading release: $tag from $url");

        $zipFilePath = self::DOWNLOAD_DIR . "/phrasea-$tag.zip";
        $this->filesystem->dumpFile($zipFilePath, $this->httpClient->request('GET', $url)->getContent());
        $this->output->writeln("Saved to: $zipFilePath");

        $unzipDir = self::DOWNLOAD_DIR . "/phrasea-doc-$tag";

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath) === true) {
            $zip->extractTo($unzipDir);
            $zip->close();
            $this->output->writeln("phrasea-$tag.zip extracted to $unzipDir");
            $this->filesystem->remove($zipFilePath);
        }

        $this->compileFiles($unzipDir, $tag);

        $semverTag = new Semver\Version($tag);
        $version = $semverTag->major . '.' . $semverTag->minor;

        return $this->generateAndPush($version);
    }

    private function exportByBranch(array $branchesByName, string $branchName): int
    {
        if (!isset($branchesByName[$branchName])) {
            $this->output->writeln("Branch $branchName not found in branches");

            return Command::FAILURE;
        }

        $this->output->writeln("Cloning phrasea branch: $branchName");

        $cloneDir = self::DOWNLOAD_DIR . '/phrasea-' . $branchName;
        $this->runCommand(
            [
                'git',
                'clone',
                '-n',
                '--depth=1',
                '--filter=tree:0',
                '-b',
                $branchName,
                '--single-branch',
                self::GITHUB_URL . '/' . $this->phrasea_repo,
                $cloneDir
            ],
            self::DOWNLOAD_DIR
        );

        $this->runCommand(
            [
                'git',
                'sparse-checkout',
                'set',
                '--no-cone',
                '/doc',
            ],
            $cloneDir
        );

        $this->runCommand(
            [
                'git',
                'checkout',
            ],
            $cloneDir
        );

        $this->compileFiles($cloneDir . '/doc', $branchName);

        return $this->generateAndPush($branchName);
    }

    private function generateAndPush(string $version): int
    {
        try {
            // create the api documentation from the json schema
            $this->runCommand(
                ['pnpm', 'run', 'gen-api-docs', 'databox'],
                self::DOCUSAURUS_PROJECT_DIR
            );

            if($this->githubToken) {
                // docusaurus will build directly in the workspace/repo directory, we first clone the doc repo
                $this->filesystem->remove(self::WORKSPACE_DIR);
                $this->filesystem->mkdir(self::WORKSPACE_DIR);

                $this->runCommand(
                    ['git', 'clone', "https://{{DOC_GITHUB_TOKEN}}@github.com/" . $this->doc_repo, self::CLONE_DIRNAME],
                    self::WORKSPACE_DIR
                );

                $versionDir = sprintf('%s/%s', self::DOCS_DIR, $version);
                $this->filesystem->mkdir($versionDir);

                $this->runCommand(
                    ['pnpm', 'build', '--out-dir', $versionDir],
                    self::DOCUSAURUS_PROJECT_DIR,
                    3600
                );

                $this->runCommand(['git', 'add', $versionDir], self::CLONE_DIR);
                $commitMessage = sprintf('update %s on %s', $version, date('c'));
                $this->runCommand(['git', 'commit', '-m', $commitMessage], self::CLONE_DIR);
                $this->runCommand(['git', 'push', '-u', 'origin', $this->doc_branch], self::CLONE_DIR);

                $this->filesystem->remove(self::WORKSPACE_DIR);
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
