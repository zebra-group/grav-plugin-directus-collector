<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Cache;
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

        if(file_exists('user/pages/.lock')) {
            if(time() - filemtime('user/pages/.lock') > ($this->config["plugins.directus"]['lockfileLifetime'] ?? 120)) {
                unlink('user/pages/.lock');
            } else {
                echo json_encode([
                    'status' => 200,
                    'message' => 'locked'
                ], JSON_THROW_ON_ERROR);
                Cache::clearCache();
                exit(200);
            }
        }

        touch('user/pages/.lock');

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

        unlink('user/pages/.lock');
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
        $requestUrl = $directusUtil->generateRequestUrl($collection, 0, $mapping['depth'], ($mapping['filter'] ?? []));
        $response = $directusUtil->get($requestUrl)->toArray();
        $slugger = new AsciiSlugger('de');

        $filePathArray = [];
        $folderList = glob($mapping['path'] . '/*' , GLOB_ONLYDIR);

        foreach($response['data'] as $dataSet) {
            if(
                isset($mapping['frontmatter']['column_slug'])
                && $mapping['frontmatter']['column_slug']
                && array_key_exists($mapping['frontmatter']['column_slug'], $dataSet)
                && !$dataSet[$mapping['frontmatter']['column_slug']]
            ) {

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

            if(array_key_exists('status', $dataSet)) {
                switch ($dataSet['status']) {
                    case 'published':
                        $frontMatter = $this->setFileHeaders($dataSet, $mapping, $collection);
                        break;
                    case 'preview':
                        if(isset($this->grav['config']['system']['env']['state']) && $this->grav['config']['system']['env']['state'] === 'preview') {
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
                if($dataSet['status'] === 'published' || ($dataSet['status'] === 'preview' && (isset($this->grav['config']['system']['env']['state']) && $this->grav['config']['system']['env']['state'] === 'preview'))) {
                    $this->createFile($frontMatter, $dataSet['id'], $mapping);
                    array_push($filePathArray, $mapping['path'] . '/' . $dataSet['id']);
                    if(isset($dataSet['translations'])) {
                        foreach ($dataSet['translations'] as $translation) {
                            $translationFrontmatter = $this->setFileHeaders($dataSet, $mapping, $collection, $translation);
                            $this->createFile($translationFrontmatter, $dataSet['id'], $mapping, $translation['languages_code']['code']);
                        }
                    }
                    Cache::clearCache();
                }
            } catch(\Exception $e) {
                dump($e);
                Cache::clearCache();
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

    /**
     * creates file in file system
     * @param string $frontMatter
     * @param $folderName
     * @param $mapping
     * @param string $translationKey
     */
    private function createFile(string $frontMatter, $folderName, $mapping, string $translationKey = '') {

        if($translationKey) {
            $filename = $mapping['filename'] . '.' . substr($translationKey, 0, 2) . '.md';
        } else {
            $filename = $mapping['filename'] . '.md';
        }

        if (!is_dir($mapping['path'] . '/' .  $folderName)) {
            mkdir($mapping['path'] . '/' . $folderName);
        }
        $fp = fopen($mapping['path'] . '/' . $folderName . '/' . $filename, 'w');
        if(file_exists($mapping['path'] . '/' . $folderName . '/data.json')) {
            unlink($mapping['path'] . '/' . $folderName . '/data.json');
        }
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
     * @param string $translation
     * @return string
     */
    private function setFileHeaders(array $dataSet, array $mapping, string $collection, array $translation = []) {
        $frontmatterContent =  '---' . "\n" .
            'title: ' . "'" . (isset($translation[$mapping['frontmatter']['column_title']]) ? htmlentities($translation[$mapping['frontmatter']['column_title']], ENT_QUOTES) : htmlentities($dataSet[$mapping['frontmatter']['column_title']], ENT_QUOTES)) . "'\n" .
            ($mapping['frontmatter']['column_sort'] ? 'sort: ' . $dataSet[$mapping['frontmatter']['column_sort']] . "\n" : '') .
            'slug: ' . ($translation[$mapping['frontmatter']['column_slug']] ?? $dataSet[$mapping['frontmatter']['column_slug']]) . "\n";

        if ( isset( $mapping['frontmatter']['column_date'] ) ) {
            $timestamp = strtotime($dataSet[$mapping['frontmatter']['column_date']]);
            $dateString = "'" . date('d-m-Y H:i', $timestamp) . "'";
            $frontmatterContent .= 'date: ' . $dateString . "\n";
        }

        if ( isset( $mapping['frontmatter']['flex'] ) && $mapping['frontmatter']['flex'] ) {
            $frontmatterContent .=
                $this->generateTaxonomySettings($dataSet, $mapping) .
                'flex:' . "\n".
                '  - collection: ' . $collection . "\n".
                '    id: ' . $dataSet['id'] . "\n" .
                '---';
        }
        else {
            $frontmatterContent .=
                $this->generateTaxonomySettings($dataSet, $mapping) .
                'directus:' . "\n".
                '    collection: ' . $collection . "\n".
                '    depth: ' . $mapping['depth'] . "\n".
                '    id: ' . $dataSet['id'] . "\n" .
                '---';
        }

        return $frontmatterContent;
    }

    /**
     * @param array $dataSet
     * @param array $mapping
     * @param array $translation
     * @return string
     */
    private function generateTaxonomySettings(array $dataSet, array $mapping, array $translation = []) {
        if(isset($dataSet[$mapping['frontmatter']['column_category']])) {
            $frontmatterContent = 'taxonomy:' . "\n" .
                '    category:' . "\n" .
                '        - ' . ($translation[$mapping['frontmatter']['column_category']] ?? $dataSet[$mapping['frontmatter']['column_category']]) . "\n";

            return $frontmatterContent;
        }

        return '';
    }
}


