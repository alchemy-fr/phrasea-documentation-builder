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

        if(0) {
            $filesystem = new Filesystem();

            // Créer le répertoire de téléchargement s'il n'existe pas
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
                $this->output->writeln("File successfully extracted to $unzipDir");
                $filesystem->remove($zipFilePath);
            }
        }
        $this->compileFiles($unzipDir, $documentationDir);
    }

    private function compileFiles(string $originDir, string $documentationDir): void
    {
        $filesystem = new Filesystem();

        $documentationDir = rtrim($documentationDir, '/');
        $originDir = rtrim($originDir, '/');
        $originDirLen = strlen($originDir);
        $generatedDocDir = $originDir . '/_generatedDoc';
        $generatedDocDirLen = strlen($generatedDocDir);

        // ---- first distribute _generatedDoc files to the matching directories
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
            $filesystem->remove($originDir . '/_generatedDoc'); // remove the original _generatedDoc directory
        }
        catch (\Exception $e) {
            // _generatedDoc directory may not exist
        }

        $this->output->writeln("");
        $this->output->writeln("");


        // ---- then patch the files in the original directory
        $translations = [];
        $scan = function($rootdir, $subdir, $depth=0) use (&$scan, $filesystem, $documentationDir, &$translations) {
            // if($depth >3) return[];

            $tab = str_repeat('  ', $depth);
            $scandir =  $rootdir . $subdir;
            $this->output->writeln(sprintf("%s Scanning %s", $tab, $scandir));
            $items = [];
            $iterator = new \DirectoryIterator($scandir);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {

                $name = $file->getFilename();
                if ($file->isDir() && ($name === '.' || $name === '..')) {
                    continue;
                }

   //              $this->output->writeln(sprintf("%s - %s", $tab, $file->getPathname()));
                if ($file->isFile()) {
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
                    $this->output->writeln(sprintf("copy %s to %s:%s",$file->getPathname(), $targetDir, $bn . $dotExtension));
                    $filesystem->mkdir($targetDir, 0777, true); // create the target directory if it does not exist
                    $filesystem->copy($file->getPathname(), $targetDir . '/' . $bn . $dotExtension, true);

                    if($dotExtension === '.md' || $dotExtension === '.mdx') {
                        $items[] = [
                            'type'  => 'doc',
                            'id'    => 'phrasea' . $subdir . '/' . $bn,
                            'label' => $bn,
                        ];
                    }
                } elseif ($file->isDir()) {
 //                   $sd = $subdir . '/' . $file->getFilename();
//                    $this->output->writeln(sprintf("%s got dir '%s' on subdir '%s' --> subdir=%s", $tab, $file->getFilename(), $subdir, $sd));
                    if(file_exists($file->getPathname() . '/_locales.yml')) {
                        foreach (Yaml::parse(file_get_contents($file->getPathname() . '/_locales.yml')) as $locale => $translation) {
                            if(!isset($translations[$locale])) {
                                $translations[$locale] = [];
                            }
                            $translations[$locale]['sidebar.tutorialSidebar.category.'.$file->getFilename()] = ['message' => $translation];
                        }
                        //   var_dump($locales);
                    }

                    $item = $scan($rootdir, $subdir . '/' . $file->getFilename(), $depth + 1);
                    if(count($item['items']) > 0) {
                        $items[] = $item;
                    }
                }
            }
            $this->output->writeln(sprintf("%s Scanned %s:", $tab, $subdir));

         //   var_dump($files);
            return [
                'type' => 'category',
                'label' => $subdir ? ucfirst(basename($subdir)) : 'Phrasea',
                'items' => $items,
            ];
        };

        $navbar =  $scan($originDir, '') ;

        $this->output->writeln(json_encode($navbar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        var_dump($translations);

        foreach ($translations as $locale => $translation) {
            $target = $documentationDir . '/i18n/' . $locale . '/docusaurus-plugin-content-docs/current.json';
            $this->output->writeln("Writing translations to: " . $target);
            if(!file_exists(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, json_encode($translation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($originDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {

            $relativePath = substr($file->getPath(), $originDirLen);

            $this->output->writeln("Processing file: " . $relativePath . ' / ' . $file->getFilename());

            if($file->isDir()) {
                $target = $documentationDir . '/docs'. $relativePath . '/' . $file->getFilename();
                $this->output->writeln("mkdir: " . $target);
                if(file_exists($file->getPathname() . '/_locales.yml')) {
                    $locales = Yaml::parse(file_get_contents($file->getPathname() . '/_locales.yml'));
                 //   var_dump($locales);
                }
            }

            $d = $documentationDir.substr($file->getPathname(), $originDirLen);
//            $filesCreatedWhileMirroring[$target] = true;

           // var_dump($file->getPathname(), ' => ', $target);

//            if (is_dir($file)) {
//                $this->mkdir($target);
//            } elseif (is_file($file)) {
//                $this->copy($file, $target, $options['override'] ?? false);
//            } else {
//                throw new IOException(\sprintf('Unable to guess "%s" file type.', $file), 0, null, $file);
//            }
        }
    }
}
