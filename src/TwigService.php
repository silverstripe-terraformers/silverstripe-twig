<?php

namespace Terraformers\Twig;

use InvalidArgumentException;
use SilverStripe\Assets\Filesystem;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ViewableData;
use Throwable;
use Twig;
use function SilverStripe\StaticPublishQueue\URLtoPath;
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

        // Check to see if a cache path can be determined for the item
        $cachePath = $this->getCacheLocation($item);

        // If a cache path can be determined, check for the existence of a cache file at that location
        if ($cachePath !== null && file_exists($cachePath)) {
            return json_decode(file_get_contents($cachePath), true);
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

        // Attempt to save the context away in cache for next time
        try {
            Filesystem::makeFolder(dirname($cache_path));
            file_put_contents($cache_path, json_encode($context));
        } catch (Throwable $e) {
            // @todo: not too sure what/where to log at the moment. Just return the context as is for now
            return $context;
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

    protected function convertDataObjectToArray(ViewableData $obj): ?array
    {
        $twigFields = $obj->config()->get('twig_fields');

        // Sanity check
        if (!$twigFields) {
            return null;
        }

        $output = [];

        foreach ($twigFields as $value) {
            $fieldName = $value;

            if ($obj instanceof DataObject) {
                $value = $obj->relField($fieldName);
            } else {
                $value = $obj->__get($fieldName);
            }

            if (!$value) {
                continue;
            }

            $output[$fieldName] = $this->convertItemToArray($value);
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
            URLtoPath($location, BASE_URL, false)
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
}
