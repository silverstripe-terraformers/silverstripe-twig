<?php

namespace Terraformers\Twig;

use InvalidArgumentException;
use SilverStripe\Assets\Filesystem;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ViewableData;
use Twig;
use function SilverStripe\StaticPublishQueue\URLtoPath;
use const BASE_PATH;
use const BASE_URL;
use const DIRECTORY_SEPARATOR;
use const PUBLIC_PATH;
use const THEMES_PATH;

class TwigViewer extends SSViewer
{
    /**
     * @var Twig\Environment|null
     */
    private $twig;

    /**
     * @var Twig\Loader\FilesystemLoader
     */
    private $loader;

    /**
     * @var string|null
     */
    private $cache_path;

    public function init(): void
    {
        if ($this->loader === null) {
            $this->loader = new Twig\Loader\FilesystemLoader(THEMES_PATH . '/app/twig/');
        }

        if ($this->twig === null) {
            // @todo add debug to config
            $this->twig = new Twig\Environment(
                $this->loader,
                [
                    'debug' => true,
                ]
            );
            $this->twig->addExtension(new Twig\Extension\DebugExtension());
        }
    }

    public static function flush()
    {
        parent::flush();

        // @todo: Add any relevant flushes
    }

    /**
     * @param \SilverStripe\View\ViewableData $item
     * @param null $arguments
     * @param null $inheritedScope
     * @return false|\SilverStripe\ORM\FieldType\DBHTMLText|string
     */
    public function process($item, $arguments = null, $inheritedScope = null)
    {
        // Current item is twig enabled, so we're good to go
        if ($item->config()->get('twig_enabled')) {
            return $this->processTwig($item);
        }

        // We twig enable the Models, rather than the Controllers, so let's check if we're in a controller and grab the
        // Model and check accordingly
        if ($item instanceof ContentController) {
            if ($item->data()->config()->get('twig_enabled')) {
                return $this->processTwig($item);
            }
        }

        return parent::process($item, $arguments, $inheritedScope);
    }

    protected function processTwig(ViewableData $item)
    {
        $this->init();

        $context = $this->getContextFromCache($item);

        return $this->getTwigTemplate()
            ->render([
                'record' => $context,
            ]);
    }

    protected function getContextFromCache(ViewableData $item): ?array
    {
        $cache_path = $this->getCacheLocation($item);

        if (file_exists($cache_path)) {
            return json_decode(file_get_contents($cache_path), true);
        }

        return $this->generateContextForItem($item);
    }

    protected function generateContextForItem(ViewableData $item): ?array
    {
        $cache_path = $this->getCacheLocation($item);

        if ($item instanceof ContentController) {
            $item = $item->data();
        }

        $context = $this->convertItemForContext($item);

        Filesystem::makeFolder(dirname($cache_path));
        file_put_contents($cache_path, json_encode($context));

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
        $loader = $this->loader;

        foreach ($this->templates as $value) {
            // Includes not supported at this time
            if (is_array($value)) {
                continue;
            }

            $twigTemplate = sprintf('%s.twig', $value);

            if ($loader->exists($twigTemplate)) {
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
    protected function convertItemForContext($item)
    {
        if (is_array($item)) {
            return $this->convertIteratorForContext($item);
        }

        if ($item instanceof DataList) {
            return $this->convertIteratorForContext($item);
        }

        if ($item instanceof ViewableData) {
            return $this->convertDataObjectForContext($item);
        }

        return $item;
    }

    /**
     * @param array|DataList $iterator
     * @return array|null
     */
    protected function convertIteratorForContext($iterator): ?array
    {
        $output = [];

        foreach ($iterator as $key => $item) {
            $output[$key] = $this->convertItemForContext($item);
        }

        return $output;
    }

    protected function convertDataObjectForContext(ViewableData $obj): ?array
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

            $output[$fieldName] = $this->convertItemForContext($value);
        }

        return $output;
    }

    protected function getCacheLocation(ViewableData $item): ?string
    {
        if ($this->cache_path !== null) {
            return $this->cache_path;
        }

        if ($item instanceof ContentController) {
            $location = $item->Link();
        } else {
            $location = str_replace('\\', '/', get_class($item));
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
            '%s%s%scache/',
            defined('PUBLIC_PATH') ? PUBLIC_PATH : BASE_PATH,
            DIRECTORY_SEPARATOR,
            $this->config()->get('dest_folder')
        );
    }
}
