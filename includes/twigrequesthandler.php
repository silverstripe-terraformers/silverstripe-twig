<?php

use Terraformers\Twig;

/**
 * Thanks to the team at silverstripe/silverstripe-staticpublishqueue for paving the way.
 *
 * @param string $cacheDir
 * @return bool
 */
return function($cacheDir)
{
    // allow content authors to avoid static cache via cookie
    if (isset($_COOKIE['bypassTwigCache'])) {
        return false;
    }

    // Convert into a full URL
    $port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80;
    $https = $port === '443' || isset($_SERVER['HTTPS']) || isset($_SERVER['HTTPS']);
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    $url = $https ? 'https://' : 'http://';
    $url .= $host . $uri;

    $cacheService = new Twig\CacheService();
    $cachePath = $cacheService->getCachePathByUrl($url);

    if (!$cachePath) {
        return false;
    }

    //check for directory traversal attack
    $realCacheDir = realpath($cacheDir);
    $realCachePath = realpath($dirname = dirname($cachePath));

    // path is outside the cache dir
    if (substr($realCachePath, 0, strlen($realCacheDir)) !== $realCacheDir) {
        return false;
    }

    $twigService = new Twig\TwigService();
    $cache = $cacheService->getCacheByPath($cachePath);

    if (!$cache) {
        return false;
    }

    $etag = '"' . md5_file($cachePath) . '"';

    if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
        header('HTTP/1.1 304', true);

        return true;
    }

    header('ETag: ' . $etag);
    header('X-Cache-Hit: ' . date(DateTime::COOKIE));
    header('content-type: text/html; charset=utf-8');
    header('vary: X-Forwarded-Protocol');

    $language = $cache['language'] ?? null;

    if ($language) {
        header('content-language: ' . $language);
    }

    echo $twigService->process($cache['context'], $cache['templates']);

    return true;
};
