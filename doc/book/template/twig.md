# Using Twig

[Twig](http://twig.sensiolabs.org/) is a template language and engine provided
as a standalone component by SensioLabs. It provides:

- Layout facilities.
- Template inheritance.
- Helpers for escaping, and the ability to provide custom helper extensions.

We provide a [TemplateRendererInterface](interface.md) wrapper for Twig via
`Mezzio\Template\TwigRenderer`.

## Installing Twig

To use the Twig wrapper, you must first install Twig

```bash
$ composer require twig/twig
```

## Using the wrapper

If instantiated without arguments, `Mezzio\Template\TwigRenderer` will create
an instance of the Twig engine, which it will then proxy to.

```php
use Mezzio\Template\TwigRenderer;

$renderer = new TwigRenderer();
```

Alternately, you can instantiate and configure the engine yourself, and pass it
to the `Mezzio\Template\TwigRenderer` constructor:

```php
use Twig_Environment;
use Twig_Loader_Array;
use Mezzio\Template\TwigRenderer;

// Create the engine instance:
$loader = new Twig_Loader_Array(include 'config/templates.php');
$twig = new Twig_Environment($loader);

// Configure it:
$twig->addExtension(new CustomExtension());
$twig->loadExtension(new CustomExtension();

// Inject:
$renderer = new TwigRenderer($twig);
```
