# Twig template support for SilverStripe 4

## Requirements

* Silverstripe ^4
* PHP ^7.3
* Patience
* A willingness to contribute

## Installation

`$ composer require silverstripe-terraformers/silverstripe-twig`

```
---
Name: app-twig
#After: '#debugbar'
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\View\SSViewer:
    class: Terraformers\Twig\TwigViewer
```

Note: Out of the box, this cannot currently be used in conjunction with Debug Bar. If your project is using Debug Bar,
you will need to add the `After: '#debugbar'` statement.

## Usage

To be added
