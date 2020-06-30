<?php

namespace Terraformers\Twig;

use SilverStripe\Assets\Filesystem;
use SilverStripe\View\ViewableData;
use const BASE_PATH;
use const BASE_URL;
use const DIRECTORY_SEPARATOR;
use const PUBLIC_PATH;

class CacheService
{
    public function saveCache(array $context, string $savePath): void
    {
        Filesystem::makeFolder(dirname($savePath));
        file_put_contents($savePath, json_encode($context));
    }

    public function getCacheByPath(?string $cachePath): ?array
    {
        // If a cache path can be determined, check for the existence of a cache file at that location
        if ($cachePath === null) {
            return null;
        }

        if (!file_exists($cachePath)) {
            return null;
        }

        $contents = file_get_contents($cachePath);

        if (strlen($contents) === 0) {
            return null;
        }

        return json_decode($contents, true);
    }

    public function getCachePathByViewableData(ViewableData $item): ?string
    {
        $path = $this->ViewableDataToPath($item);

        if ($path === null) {
            return null;
        }

        return sprintf(
            '%s%s.json',
            $this->getDestPath(),
            $this->UrlToPath($path, BASE_URL, false)
        );
    }

    public function getCachePathByUrl(string $url): string
    {
        return sprintf(
            '%s%s.json',
            $this->getDestPath(),
            $this->UrlToPath($url, BASE_URL, false)
        );
    }

    protected function ViewableDataToPath(ViewableData $item): ?string
    {
        $location = null;

        if ($item->hasMethod('Link')) {
            // Check if ViewableData has a Link() method. Note: We can't just use __get('Link') because this method
            // will end up returning ->Link, not ->Link()
            $location = $item->Link();
        } elseif ($item->hasField('Link')) {
            // Next check to see if there is a Link field
            $location = $item->Link;
        } elseif ($item->__get('ID')) {
            // Last chance, if the ViewableData is a DataObject (or if the ViewableData provides us with an ID through
            // a method or field, then we can store the cache using ClassName and ID
            $location = sprintf(
                '%s/%s',
                str_replace('\\', '/', get_class($item)),
                $item->__get('ID')
            );
        }

        return $location;
    }

    /**
     * @return string
     */
    protected function getDestPath()
    {
        return sprintf(
            '%s%scache/',
            defined('PUBLIC_PATH') ? PUBLIC_PATH : BASE_PATH,
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Method straight up stolen from the team at silverstripe/silverstripe-staticpublishqueue. Thanks, team.
     *
     * @param string $url
     * @param string $baseURL
     * @param bool $domainBasedCaching
     * @return string|null
     */
    public static function UrlToPath(string $url, string $baseURL = '', bool $domainBasedCaching = false): ?string
    {
        // parse_url() is not multibyte safe, see https://bugs.php.net/bug.php?id=52923.
        // We assume that the URL hsa been correctly encoded either on storage (for SiteTree->URLSegment),
        // or through URL collection (for controller method names etc.).
        $urlParts = @parse_url($url);

        // query strings are not yet supported so we need to bail is there is one present
        if (!empty($urlParts['query'])) {
            return null;
        }

        // Remove base folders from the URL if webroot is hosted in a subfolder)
        $path = isset($urlParts['path'])
            ? $urlParts['path']
            : '';

        if (mb_substr(mb_strtolower($path), 0, mb_strlen($baseURL)) == mb_strtolower($baseURL)) {
            $urlSegment = mb_substr($path, mb_strlen($baseURL));
        } else {
            $urlSegment = $path;
        }

        // Normalize URLs
        $urlSegment = trim($urlSegment, '/');

        $filename = $urlSegment ?: 'home';

        if ($domainBasedCaching) {
            if (!$urlParts) {
                throw new \LogicException('Unable to parse URL');
            }

            if (isset($urlParts['host'])) {
                $filename = $urlParts['host'] . '/' . $filename;
            }
        }

        $dirName = dirname($filename);
        $prefix = '';

        if ($dirName != '/' && $dirName != '.') {
            $prefix = $dirName . '/';
        }

        return $prefix . basename($filename);
    }
}
