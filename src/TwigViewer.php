<?php

namespace Terraformers\Twig;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ViewableData;

class TwigViewer extends SSViewer
{
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
            return TwigService::create()->process($item, $this->templates);
        }

        return parent::process($item, $arguments, $inheritedScope);
    }

    public function itemIsTwigEnabled(ViewableData $item): bool
    {
        // Current ViewableData is itself twig enabled, so we're good to go
        if ($item->config()->get('twig_enabled')) {
            return true;
        }

        // If the ViewableData is also not a ContentController, then we're done here, it's not twig enabled
        if (!$item instanceof ContentController) {
            return false;
        }

        // Check whether the data record on the ContentController is twig enabled
        return $item->data()->config()->get('twig_enabled');
    }
}
