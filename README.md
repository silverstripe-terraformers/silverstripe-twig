# Twig template support for SilverStripe 4

This module is only days into development! There's still plenty missing, and a high likelihood that things will change
as our desires change.

## Requirements

* Silverstripe ^4
* PHP ^7.3
* Patience
* A willingness to contribute

## Missing

This is incredibly early alpha, so you're going to have to bear with us. Currenly there is no mechanism for clearing
caches (if you have it enabled). We'll look to add some sort of extension for this mechanism as soon as possible.

## Installation

```
$ composer require silverstripe-terraformers/silverstripe-twig
```

Replace `SSViewer` with the `TwigViewer`. Note: This will only affect models/controllers that you also flag as
`twig_enabled` (more on this later):

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
you will need to add/uncomment the `After: '#debugbar'` statement.

## Usage

In order to start using Twig templates for your `Controllers`/`Models`, you will need to let the `Viewer` know which
have been enabled. You can do this by adding the config field `twig_enabled` to either your `Controller` or `Model`.

The below example would enable twig rendering for all of your Page types.

Adding config to your PHP class:

```php
class Page extends SiteTree
{
    private static $twig_enabled = true;
}
``` 

Adding config through yml:

```yaml
Page:
  twig_enabled: true
```

## Data transformation

To render our Twig templates, we will turn our `ViewableData` into a basic json format, and we will then feed that to
the Twig `Environment` along with the template to be rendered.

In order for us to know what data you want to transform, you will need to add a second config field called
`twig_fields`. This should contain all fields/relationships that you would like transformed/exported.

Note: You will need to add this config to **all** models that you wish to have exported fields. This would include
adding it to classes like `SilverStripe\Assets\File` (example below).

Adding config to your PHP class:

```php
namespace App\Page;

class MyPage extends Page
{
    private static $has_many = [
        'Images' => Image::class,
    ];

    private static $twig_enabled = true;
    
    private static $twig_fields = [
        'Title',
        'Content',
        'Images',
    ];
}
``` 

Adding config through yml:

```yaml
# This config on DataObject means that every record exported will have (at a minimum) its ID and ClassName exported 
SilverStripe\ORM\DataObject:
  twig_fields:
    - ClassName
    - ID

# File (which also includes Image) to export the relevant fields
SilverStripe\Assets\File:
  twig_fields:
    - AbsoluteLink
    - Link
    - Name
    - Title

App\Page\MyPage:
  twig_enabled: true
  twig_fields:
    - Title
    - Content
    - Images
```

## Creating templates

For now, Twig templates need to be stored under `$themesDir/app/twig`. We'll make this configurable soon.

### What templates should I create, and where?

The template that is used to render our view will always be the highest level template that we can find. EG: consider
the page with class `App\Page\MyPage`, which extends `App\Page\MyBasePage`, which extends `Page`. The template stack
will be (something like):
- `App\Page\MyPage`
- `App\Page\MyBasePage`
- `Page`

We'll go looking for files matching the following (and in this order), and we'll stop when we find one:
- `themes\app\twig\App\Page\MyPage.twig`
- `themes\app\twig\App\Page\MyBasePage.twig`
- `themes\app\twig\Page.twig`

## Caching

In order to speed things up for subsequent requests, we can create cached data files that store the json blob for any
given request. These are stored in `public/cache`.

By default, this caching mechanism is disabled, but you can enable it for particular models/controllers by adding the
`twig_cache_enabled` config.

The examle below would essentially enable caching for all of your Page types.

Adding config to your PHP class:

```php
namespace App\Page;

class Page extends SiteTree
{
    private static $twig_cache_enabled = true;
}
``` 

Adding config through yml:

```yaml
Page:
  twig_cache_enabled:
```

**Please note**: There is no "partial cache" mechanism at this time. You cannot have only part of your request data
being cached, while other parts of it are dynamic. You should look at this as a similar cache mechanic as static
publisher. Where you require dynamic content, you may need to consider using XHR to populate that content.

The goal (soon) will be to also provide a mechanism for you to clear these caches (probably on page publish/etc).

## Handling requests

When/if we have the data for a request cached, there is no need to spin up the framework to render the template - 
instead we can just spin up Twig, and feed it what it needs to generate our page.

To set this up, you'll need to add this to your `public/index.php` file:

```php
// Autoloader section
// ...

$requestHandler = require '../vendor/silverstripe-terraformers/silverstripe-twig/includes/twigrequesthandler.php';

// successful cache hit
if ($requestHandler('cache') !== false) {
    die;
} else {
    header('X-Cache-Miss: ' . date(DateTime::COOKIE));
}

// Default application stuff
// ...
```
