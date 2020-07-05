<?php

namespace Terraformers\Twig;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ViewableData;

class TwigViewer extends SSViewer
{
    /**
     * @var TwigService|null
     */
    private $twigService;

    /**
     * @var CacheService
     */
    private $cacheService;

    /**
     * @var DataService
     */
    private $dataService;

    /**
     * @param ViewableData $item
     * @param null $arguments
     * @param null $inheritedScope
     * @return false|\SilverStripe\ORM\FieldType\DBHTMLText|string
     * @throws \Throwable
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function process($item, $arguments = null, $inheritedScope = null)
    {
        if ($this->itemIsTwigEnabled($item)) {
            return $this->renderTwig($item, $this->templates);
        }

        return parent::process($item, $arguments, $inheritedScope);
    }

    /**
     * @param ViewableData $item
     * @param array|string $templates
     * @return false|string
     * @throws \Throwable
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function renderTwig(ViewableData $item, $templates)
    {
        if (!$this->itemIsCacheEnabled($item)) {
            $context = $this->getDataService()->getContextForItem($item);

            return $this->getTwigService()->process($context, $templates);
        }

        $cachePath = $this->getCacheService()->getCachePathByViewableData($item);
        $cache = $this->getCacheService()->getCacheByPath($cachePath);

        if ($cache === null) {
            $context = $this->getDataService()->getContextForItem($item);

            $cache = [
                'context' => $context,
                'templates' => $templates,
            ];

            $this->getCacheService()->saveCache($cache, $cachePath);
        }

        $context = $cache['context'];
        $templates = $cache['templates'];

        return $this->getTwigService()->process($context, $templates);
    }

    protected function getTwigService(): TwigService
    {
        if ($this->twigService !== null) {
            return $this->twigService;
        }

        $this->twigService = Injector::inst()->create(TwigService::class);

        return $this->twigService;
    }

    protected function getCacheService(): CacheService
    {
        if ($this->cacheService !== null) {
            return $this->cacheService;
        }

        $this->cacheService = Injector::inst()->create(CacheService::class);

        return $this->cacheService;
    }

    protected function getDataService(): DataService
    {
        if ($this->dataService !== null) {
            return $this->dataService;
        }

        $this->dataService = Injector::inst()->create(DataService::class);

        return $this->dataService;
    }

    protected function itemIsTwigEnabled(ViewableData $item): bool
    {
        // Current ViewableData is itself twig enabled, so we're good to go
        if ($item->config()->get('twig_enabled')) {
            return true;
        }

        // If the ViewableData is a ContentController, then let's also check the record attached to it
        if ($item instanceof ContentController) {
            // Check whether the data record on the ContentController is twig enabled
            return $item->data()->config()->get('twig_enabled') ?? false;
        }

        // Neither the controller or record were twig enabled
        return false;
    }

    protected function itemIsCacheEnabled(ViewableData $item): bool
    {
        // Current ViewableData is itself has twig cache enabled, so we're good to go
        if ($item->config()->get('twig_cache_enabled')) {
            return true;
        }

        // If the ViewableData is a ContentController, then let's also check the record attached to it
        if ($item instanceof ContentController) {
            // Check whether the data record on the ContentController has twig cache enabled
            return $item->data()->config()->get('twig_cache_enabled') ?? false;
        }

        // Neither the controller or record were twig cache enabled
        return false;
    }
}
