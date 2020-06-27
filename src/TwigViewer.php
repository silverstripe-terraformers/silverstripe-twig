<?php

namespace Terraformers\Twig;

use InvalidArgumentException;
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
     * @var bool
     */
    private static $domain_based_caching = false;

    private static $dest_folder = 'cache';

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

        $context = $this->generateContextForItem($item);

        return $this->getTwigTemplate()
            ->render([
                'record' => $context,
            ]);
    }

    protected function generateContextForItem(ViewableData $item): ?array
    {
        $cache = $this->determineCacheLocation($item);

        if ($item instanceof ContentController) {
            return $this->convertItemForContext($item->data());
        }

        return $this->convertItemForContext($item);
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

    protected function determineCacheLocation(ViewableData $item): ?string
    {
        if ($item instanceof ContentController) {
            $location = $item->Link();
        } else {
            $location = get_class($item);
        }

        return URLtoPath($location, BASE_URL, TwigViewer::config()->get('domain_based_caching'));
    }

    /**
     * @return string
     */
    protected function getDestPath()
    {
        $base = defined('PUBLIC_PATH') ? PUBLIC_PATH : BASE_PATH;
        return $base . DIRECTORY_SEPARATOR . TwigViewer::config()->get('dest_folder');
    }
}
