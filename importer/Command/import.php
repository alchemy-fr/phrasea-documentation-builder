<?php

namespace App\Command;

use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'import')]
class import extends Command
{
    private const GITHUB_API_URL = 'https://api.github.com';
    private InputInterface $input;
    private OutputInterface $output;
    private HttpClientInterface $httpClient;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

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
            // todo: keep only the latest release of every major+minor version
            // for now we keep the highest tag version
            uksort($releasesByTag, function ($a, $b) {
                return version_compare($b, $a);
            });
//            var_dump($releasesByTag);
            foreach ($releasesByTag as $tag => $url) {
                $this->generateForRelease($tag, $url);
                break;  // only highest version for now
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
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
            'tab' => $tag
        ];
        $this->output->writeln("Writing version to: " . $target);
        file_put_contents($target, json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->compileFiles($unzipDir, $documentationDir);
    }

    private function compileFiles(string $originDir, string $documentationDir): void
    {
        $filesystem = new Filesystem();

        $documentationDir = rtrim($documentationDir, '/');
        $originDir = rtrim($originDir, '/');
        $generatedDocDir = $originDir . '/_generatedDoc';
        $generatedDocDirLen = strlen($generatedDocDir);

        // ---- first dispatch _generatedDoc files to the matching directories
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedDocDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relativePathname = substr($file->getPathname(), $generatedDocDirLen);

                $this->output->writeln($relativePathname . ' => ' . $file->getFilename());
                if ($file->isDir()) {
                    $d = $originDir . $relativePathname;
                    if (!$filesystem->exists($d)) {
                        $filesystem->mkdir($d);
                        $this->output->writeln("Created directory: $d");
                    }
                }
                elseif ($file->isFile()) {
                    $d = $originDir . $relativePathname;
                    if (!$filesystem->exists($d) || !file_exists($d)) {
                        $filesystem->copy($file->getPathname(), $d, true);
                        $this->output->writeln("Copied file: " . $file->getPathname() . " to " . $d);
                    }
                    else {
                        $this->output->writeln("File already exists, skipping: " . $d);
                    }
                }
            }

            // remove the _generatedDoc directory
            $filesystem->remove($originDir . '/_generatedDoc');
        }
        catch (\Exception $e) {
            // _generatedDoc directory can not exist
        }

        $this->output->writeln("");

        // ---- then dispatch the files in the documentation directory
        $translations = [];
        $scan = function($rootdir, $subdir, $depth=0) use (&$scan, $filesystem, $documentationDir, &$translations) {
            $tab = str_repeat('  ', $depth);
            $scandir =  $rootdir . $subdir;
            $this->output->writeln(sprintf("%s Scanning %s", $tab, $scandir));
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
                    $this->output->writeln(sprintf("%scopy %s to %s:%s", $tab, $file->getPathname(), $targetDir, $bn . $dotExtension));
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

                    $scan($rootdir, $subdir . '/' . $file->getFilename(), $depth + 1);
                }
            }
            $this->output->writeln(sprintf("%s Scanned %s:", $tab, $subdir));
        };

        $scan($originDir, '') ;

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
