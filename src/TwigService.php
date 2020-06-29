<?php

namespace Terraformers\Twig;

use InvalidArgumentException;
use SilverStripe\Assets\Filesystem;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\View\ViewableData;
use Throwable;
use Twig;
use const BASE_PATH;
use const BASE_URL;
use const DIRECTORY_SEPARATOR;
use const PUBLIC_PATH;
use const THEMES_PATH;

class TwigService
{
    use Injectable;

    /**
     * @var Twig\Environment|null
     */
    private $twig;

    /**
     * @var Twig\Loader\FilesystemLoader
     */
    private $loader;

    /**
     * @var array
     */
    private $templates = [];

    /**
     * @var string|null
     */
    private $cache_path;

    /**
     * @var array
     */
    private $local_cache;

    /**
     * Temporary thing while we're developing... Saves you from having to remove the cache file each time you change
     * something.
     *
     * @todo remove later on (or move to config if we decide to keep it)
     *
     * @var bool
     */
    private $use_local_cache = true;

    public function __construct()
    {
        $this->loader = new Twig\Loader\FilesystemLoader(THEMES_PATH . '/app/twig/');

        // @todo add debug to config
        $this->twig = new Twig\Environment(
            $this->loader,
            [
                'debug' => true,
            ]
        );

        $this->twig->addExtension(new Twig\Extension\DebugExtension());
    }

    /**
     * @param ViewableData $item
     * @param array|string $templates
     * @return false|string
     * @throws Twig\Error\LoaderError
     * @throws Twig\Error\RuntimeError
     * @throws Twig\Error\SyntaxError
     * @throws \Throwable
     */
    public function process(ViewableData $item, $templates)
    {
        // Store templates locally. Convert to array if required
        $this->templates = is_array($templates)
            ? $templates
            : [$templates];
        // Get the context for this item. This can come from a cache file, or be freshly generated
        $context = $this->getContextForItem($item);

        return $this->getTwigTemplate()
            ->render([
                'record' => $context,
            ]);
    }

    protected function getContextForItem(ViewableData $item): ?array
    {
        // When the item passed to this Service is a Controller, we instead want to use its data record
        if ($item instanceof ContentController) {
            $item = $item->data();
        }

        if ($this->use_local_cache) {
            if ($this->local_cache !== null) {
                return $this->local_cache;
            }
        } else {
            // Check to see if a cache path can be determined for the item
            $cachePath = $this->getCacheLocation($item);

            // If a cache path can be determined, check for the existence of a cache file at that location
            if ($cachePath !== null && file_exists($cachePath)) {
                return json_decode(file_get_contents($cachePath), true);
            }
        }

        // If there is no cached file, then we need to generate a new context
        return $this->generateContextForItem($item);
    }

    protected function generateContextForItem(ViewableData $item): ?array
    {
        // Converting the item to array can be a lengthy processes depending on how many dependant object there are
        $context = $this->convertItemToArray($item);
        // Check to see if a cache path can be determined for the item
        $cache_path = $this->getCacheLocation($item);

        // Some types of ViewableData have no form of identifier, and therefor cannot be cached, so just return the
        // context as is
        if ($cache_path === null) {
            return $context;
        }

        if ($this->use_local_cache) {
            $this->local_cache = $context;
        } else {
            // Attempt to save the context away in cache for next time
            try {
                Filesystem::makeFolder(dirname($cache_path));
                file_put_contents($cache_path, json_encode($context));
            } catch (Throwable $e) {
                // @todo: not too sure what/where to log at the moment. Just return the context as is for now
                return $context;
            }
        }

        return $context;
    }

    /**
     * @return string|Twig\Template|Twig\TemplateWrapper
     * @throws Twig\Error\LoaderError
     * @throws Twig\Error\RuntimeError
     * @throws Twig\Error\SyntaxError
     * @throws InvalidArgumentException
     */
    protected function getTwigTemplate()
    {
        // Process our template stack until we find a matching twig template
        foreach ($this->templates as $value) {
            // @todo: includes not supported at this time
            if (is_array($value)) {
                continue;
            }

            $twigTemplate = sprintf('%s.twig', $value);

            // Found it! Return it
            if ($this->loader->exists($twigTemplate)) {
                return $this->twig->load($twigTemplate);
            }
        }

        throw new InvalidArgumentException(
            sprintf('Unable to find corresponding twig templates in stack %s', implode(', ', $this->templates))
        );
    }

    /**
     * @param mixed $item
     * @return mixed
     */
    protected function convertItemToArray($item)
    {
        if (is_array($item)) {
            return $this->convertIteratorToArray($item);
        }

        if ($item instanceof DataList) {
            return $this->convertIteratorToArray($item);
        }

        if ($item instanceof ArrayList) {
            return $this->convertIteratorToArray($item);
        }

        if ($item instanceof ViewableData) {
            return $this->convertDataObjectToArray($item);
        }

        return $item;
    }

    /**
     * @param array|DataList $iterator
     * @return array|null
     */
    protected function convertIteratorToArray($iterator): ?array
    {
        $output = [];

        foreach ($iterator as $key => $item) {
            $output[$key] = $this->convertItemToArray($item);
        }

        return $output;
    }

    protected function convertDataObjectToArray(ViewableData $item): ?array
    {
        // Using isInDB() and not exists() because exists() can sometimes contain additional checks (like checking for
        // the binary file for a File) that we don't want to consider for this data transformation
        if ($item instanceof DataObject && !$item->isInDB()) {
            return null;
        }

        if ($item instanceof ArrayData) {
            // You can't define twig_fields for a DataList that was dynamically created, so we'll just grab all of the
            // fields that it contains
            $twigFields = array_keys($item->toMap());
        } else {
            // Any other sort of ViewableData should have twig_fields defined
            $twigFields = $item->config()->get('twig_fields');
        }

        // Sanity check
        if (!$twigFields) {
            return null;
        }

        $output = [];

        foreach ($twigFields as $alias => $fieldName) {
            // We can optionally write twig_fields as AliasFieldName => getFieldValue
            if (!is_string($alias)) {
                $alias = $fieldName;
            }

            if ($item instanceof DataObject) {
                $value = $item->relField($fieldName);
            } else {
                $value = $item->__get($fieldName);
            }

            if (!$value) {
                continue;
            }

            $output[$alias] = $this->convertItemToArray($value);
        }

        return $output;
    }

    protected function getCacheLocation(ViewableData $item): ?string
    {
        if ($this->cache_path !== null) {
            return $this->cache_path;
        }

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

        if (!$location) {
            return null;
        }

        $this->cache_path = sprintf(
            '%s%s.json',
            $this->getDestPath(),
            $this->UrlToPath($location, BASE_URL, false)
        );

        return $this->cache_path;
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

    public function UrlToPath($url, $baseURL = '', $domainBasedCaching = false): ?string
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

        $filename = $urlSegment ?: "index";

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
