<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\DirectusCollector\Utility\DirectusCollectorUtility;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\String\Slugger\AsciiSlugger;


/**
 * Class DirectusCollectorPlugin
 * @package Grav\Plugin
 */
class DirectusCollectorPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
    * Composer autoload.
    *is
    * @return ClassLoader
    */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0]
        ]);
    }

    /**
     * triggers if page is initialized
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function onPageInitialized() {
        $this->processWebhooks($this->grav['uri']->route());
    }

    /**
     * check for webhook
     * @param string $route
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function processWebhooks(string $route) {
        switch ($route) {
            case '/' . $this->config["plugins.directus"]['directus']['hookPrefix'] . '/update':
                $this->update();
                break;
        }
    }

    /**
     * main function
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function update() {

        $directusUtil = new DirectusCollectorUtility(
            $this->config["plugins.directus"]['directus']['directusAPIUrl'],
            $this->grav,
            $this->config["plugins.directus"]['directus']['email'],
            $this->config["plugins.directus"]['directus']['password'],
            $this->config["plugins.directus"]['directus']['token'],
            isset($this->config["plugins.directus"]['disableCors']) && $this->config["plugins.directus"]['disableCors']
        );

        foreach ($this->config()['mapping'] as $collection => $mapping) {
            try {
                $this->processCollection($collection, $mapping, $directusUtil);
            } catch (\Exception $e) {
                dump($e);
                $this->grav['debugger']->addException($e);
                exit(500);
            }

        }
        echo json_encode([
            'status' => 'success',
            'message' => 'Sites synchronized'
        ]);
        exit(200);
    }

    /**
     * processes the given collection and generates files
     * @param string $collection
     * @param array $mapping
     * @param object $directusUtil
     * @return bool
     */
    private function processCollection(string $collection, array $mapping, object $directusUtil) {

        $requestUrl = $directusUtil->generateRequestUrl($collection, 0, $mapping['depth']);
        $response = $directusUtil->get($requestUrl)->toArray();
        $slugger = new AsciiSlugger('de');

        $filePathArray = [];
        $folderList = glob($mapping['path'] . '/*' , GLOB_ONLYDIR);

        foreach($response['data'] as $dataSet) {
            array_push($filePathArray, $mapping['path'] . '/' . $dataSet['id']);
            if(!array_key_exists($mapping['frontmatter']['column_slug'], $dataSet) || !$dataSet[$mapping['frontmatter']['column_slug']] ) {

                $slug = $slugger->slug($dataSet[$mapping['frontmatter']['column_title']]);
                $dataSet[$mapping['frontmatter']['column_slug']] = $slug->lower()->toString();

                try {
                    $data = [
                        $mapping['frontmatter']['column_slug'] => $dataSet[$mapping['frontmatter']['column_slug']]
                    ];
                    /** @var \Symfony\Component\HttpClient\Response\CurlResponse  $response */
                    $response = $directusUtil->update($collection, $dataSet['id'], $data);
                } catch(\Exception $e) {
                    dump($e);
                    $this->grav['debugger']->addException($e);
                    exit(500);
                }
            }
            $frontMatter = '';

            if(array_key_exists('status', $dataSet) && ) {
                switch ($dataSet['status']) {
                    case 'published':
                        $frontMatter = $this->setFileHeaders($dataSet, $mapping, $collection);
                        break;
                    case 'preview':
                        if($this->config()['environment_status'] === 'preview') {
                            $frontMatter = $this->setFileHeaders($dataSet, $mapping, $collection);
                        } else {
                            $frontMatter = $this->setRedirectFileHeaders();
                        }
                        break;
                    case 'draft':
                        $frontMatter = $this->setRedirectFileHeaders();
                        break;
                }
            } else {
                $frontMatter = $this->setFileHeaders($dataSet, $mapping, $collection);
            }

            try {
                $this->createFile($frontMatter, $dataSet['id'], $mapping);
            } catch(\Exception $e) {
                dump($e);
                $this->grav['debugger']->addException($e);
                exit(500);
            }

        }

        foreach($folderList as $folderpath) {
            if(!in_array($folderpath, $filePathArray)) {
                $it = new RecursiveDirectoryIterator($folderpath, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it,
                    RecursiveIteratorIterator::CHILD_FIRST);
                foreach($files as $file) {
                    if ($file->isDir()){
                        rmdir($file->getRealPath());
                    } else {
                        unlink($file->getRealPath());
                    }
                }
                rmdir($folderpath);
            }
        }

        return true;

    }

    private function cleanupFolders(string $collection, array $dataSet, array $mapping) {
        $folderList = glob($mapping['path'] . '/*' , GLOB_ONLYDIR);
        dump($folderList);

    }

    /**
     * creates file in file system
     * @param string $frontMatter
     * @param $folderName
     * @param $mapping
     */
    private function createFile(string $frontMatter, $folderName, $mapping) {
        if (!is_dir($mapping['path'] . '/' .  $folderName)) {
            mkdir($mapping['path'] . '/' . $folderName);
        }
        $fp = fopen($mapping['path'] . '/' . $folderName . '/' . $mapping['filename'], 'w');
        fwrite($fp, $frontMatter);
        fclose($fp);
    }

    /**
     * creates not found frontmatter
     * @return string
     */
    private function setRedirectFileHeaders() {

        $content =  '---' . "\n" .
            'redirect: \'' . $this->config()['redirect_route'] . '\'' . "\n" .
            'sitemap:' . "\n" .
            '    ignore: true' . "\n" .
            'published: false' . "\n" .
            '---' . "\n";
        return $content;
    }

    /**
     * creates frontmatter string
     * @param array $dataSet
     * @param array $mapping
     * @param string $collection
     * @return string
     */
    private function setFileHeaders(array $dataSet, array $mapping, string $collection) {
        $timestamp = strtotime($dataSet[$mapping['frontmatter']['column_date']]);
        $dateString = "'" . date('d-m-Y H:i', $timestamp) . "'";

        $frontmatterContent =  '---' . "\n" .
            'title: ' . "'" . $dataSet[$mapping['frontmatter']['column_title']] . "'\n" .
            'date: ' . $dateString . "\n" .
            'sort: ' . $dataSet[$mapping['frontmatter']['column_sort']] . "\n" .
            'slug: ' . $dataSet[$mapping['frontmatter']['column_slug']] . "\n" .
            'directus:' . "\n".
            '    collection: ' . $collection . "\n".
            '    depth: ' . $mapping['depth'] . "\n".
            '    id: ' . $dataSet['id'] . "\n" .
            '---';

        if(isset($dataSet[$mapping['frontmatter']['column_category']])) {
            $frontmatterContent .=  'taxonomy:' . "\n".
                                    '    category:' . "\n".
                                    '        - ' . $dataSet[$mapping['frontmatter']['column_category']] . "\n";
        }

        return $frontmatterContent;
    }
}
