<?php

namespace Terraformers\Twig;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\View\ViewableData;

class DataService
{

    use Injectable;

    /**
     * @param ViewableData $item
     * @return array|null
     */
    public function getContextForItem(ViewableData $item): ?array
    {
        // When the item passed to this Service is a Controller, we instead want to use its data record
        if ($item instanceof ContentController) {
            $item = $item->data();
        }

        return $this->convertItemToArray($item);
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
            return $this->convertViewableDataToArray($item);
        }

        if (is_string($item)) {
            return ShortcodeParser::get_active()->parse($item);
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

    protected function convertViewableDataToArray(ViewableData $item): ?array
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
}
