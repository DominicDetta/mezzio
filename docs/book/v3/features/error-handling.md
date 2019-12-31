# Error Handling

We recommend that your code raise exceptions for conditions where it cannot
gracefully recover. Additionally, we recommend that you have a reasonable PHP
`error_reporting` setting that includes warnings and fatal errors:

```php
error_reporting(E_ALL & ~E_USER_DEPRECATED & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
```

If you follow these guidelines, you can then write or use middleware that does
the following:

- sets an error handler that converts PHP errors to `ErrorException` instances.
- wraps execution of the handler (`$handler->handle()`) with a try/catch block.

As an example:

```php
function (ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
{
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if (! (error_reporting() & $errno)) {
            // Error is not in mask
            return;
        }
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    try {
        $response = $handler->handle($request);
        return $response;
    } catch (Throwable $e) {
    }

    restore_error_handler();

    $response = new TextResponse(sprintf(
        "[%d] %s\n\n%s",
        $e->getCode(),
        $e->getMessage(),
        $e->getTraceAsString()
    ), 500);
}
```

You would then pipe this as the outermost (or close to outermost) layer of your
application:

```php
$app->pipe($errorMiddleware);
```

So that you do not need to do this, we provide an error handler for you, via
laminas-stratigility: `Laminas\Stratigility\Middleware\ErrorHandler`.

This implementation allows you to both:

- provide a response generator, invoked when an error is caught; and
- register listeners to trigger when errors are caught.

We provide the factory `Mezzio\Container\ErrorHandlerFactory` for
generating the instance; it should be mapped to the service
`Laminas\Stratigility\Middleware\ErrorHandler`.

We provide two error response generators for you:

- `Mezzio\Middleware\ErrorResponseGenerator`, which optionally will
  accept a `Mezzio\Template\TemplateRendererInterface` instance, and a
  template name. When present, these will be used to generate response content;
  otherwise, a plain text response is generated that notes the request method
  and URI.

- `Mezzio\Middleware\WhoopsErrorResponseGenerator`, which uses
  [whoops](http://filp.github.io/whoops/) to present detailed exception
  and request information; this implementation is intended for development
  purposes.

Each also has an accompanying factory for generating the instance:

- `Mezzio\Container\ErrorResponseGeneratorFactory`
- `Mezzio\Container\WhoopsErrorResponseGeneratorFactory`

Map the service `Mezzio\Middleware\ErrorResponseGenerator` to one of
these two factories in your configuration:

```php
use Mezzio\Container;
use Mezzio\Middleware;
use Laminas\Stratigility\Middleware\ErrorHandler;

return [
    'dependencies' => [
        'factories' => [
            ErrorHandler::class => Container\ErrorHandlerFactory::class,
            Middleware\ErrorResponseGenerator::class => Container\ErrorResponseGeneratorFactory::class,
        ],
    ],
];
```

> ### Use development mode configuration to enable whoops
>
> You can specify the above in one of your `config/autoload/*.global.php` files,
> to ensure you have a production-capable error response generator.
>
> If you are using [laminas-development-mode](https://github.com/laminas/laminas-development-mode)
> in your application (which is provided by default in the skeleton
> application), you can toggle usage of whoops by adding configuration to the file
> `config/autoload/development.local.php.dist`:
>
> ```php
> use Mezzio\Container;
> use Mezzio\Middleware;
>
> return [
>     'dependencies' => [
>         'factories' => [
>             Middleware\WhoopsErrorResponseGenerator::class => Container\WhoopsErrorResponseGeneratorFactory::class,
>         ],
>     ],
> ];
> ```
>
> When you enable development mode, whoops will then be enabled; when you
> disable development mode, you'll be using your production generator.
>
> If you are not using laminas-development-mode, you can define a
> `config/autoload/*.local.php` file with the above configuration whenever you
> want to enable whoops.

## Listening for errors

When errors occur, you may want to _listen_ for them in order to provide
features such as logging. `Laminas\Stratigility\Middleware\ErrorHandler` provides
the ability to do so via its `attachListener()` method.

This method accepts a callable with the following signature:

```php
function (
    Throwable $error,
    ServerRequestInterface $request,
    ResponseInterface $response
) : void
```

The response provided is the response returned by your error response generator,
allowing the listener the ability to introspect the generated response as well.

As an example, you could create a logging listener as follows:

```php
namespace Acme;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class LoggingErrorListener
{
    /**
     * Log format for messages:
     *
     * STATUS [METHOD] path: message
     */
    const LOG_FORMAT = '%d [%s] %s: %s';

    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Throwable $error, ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->logger->error(sprintf(
            self::LOG_FORMAT,
            $response->getStatusCode(),
            $request->getMethod(),
            (string) $request->getUri(),
            $error->getMessage()
        ));
    }
}
```

You could then use a [delegator factory](container/delegator-factories.md) to
create your logger listener and attach it to your error handler:

```php
namespace Acme;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;

class LoggingErrorListenerDelegatorFactory
{
    public function __invoke(ContainerInterface $container, string $name, callable $callback) : ErrorHandler
    {
        $listener = new LoggingErrorListener($container->get(LoggerInterface::class));
        $errorHandler = $callback();
        $errorHandler->attachListener($listener);
        return $errorHandler;
    }
}
```

## Handling more specific error types

You could also write more specific error handlers. As an example, you might want
to catch `UnauthorizedException` instances specifically, and display a login
page:

```php
function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($renderer) : ResponseInterface
{
    try {
        $response = $handler->handle($request);
        return $response;
    } catch (UnauthorizedException $e) {
    }

    return new HtmlResponse(
        $renderer->render('error::unauthorized'),
        401
    );
}
```

You could then push this into a middleware pipe only when it's needed:

```php
$app->get('/dashboard', [
    $unauthorizedHandlerMiddleware,
    $middlewareThatChecksForAuthorization,
    $middlewareBehindAuthorizationWall,
], 'dashboard');
```

## Default delegates

`Mezzio\Application` manages an internal middleware pipeline; when you
call `$handler->handle()`, `Application` is popping off the next middleware in
the queue and dispatching it.

What happens when that queue is exhausted?

That situation indicates an error condition: no middleware was capable of
returning a response. This could either mean a problem with the request (HTTP
400 "Bad Request" status) or inability to route the request (HTTP 404 "Not
Found" status).

In order to report that information, we provide a specialized handler,
`Mezzio\Handler\NotFoundHandler`, which you should compose as the
innermost layer of your application pipeline. It will report a 404
response, optionally using a composed template renderer to do so.

We provide a factory, `Mezzio\Container\NotFoundHandlerFactory`, for
creating an instance, and this should be mapped to the
`Mezzio\Handler\NotFoundHandler` service:

```php
use Mezzio\Container;
use Mezzio\Handler;

return [
    'dependencies' => [
        'factories' => [
            Handler\NotFoundHandler::class => Container\NotFoundHandlerFactory::class,
        ],
    ],
];
```

The factory will consume the following services:

- `Mezzio\Template\TemplateRendererInterface` (optional): if present,
  the renderer will be used to render a template for use as the response
  content.

- `config` (optional): if present, it will use the
  `$config['mezzio']['error_handler']['template_404']` value
  as the template to use when rendering; if not provided, defaults to
  `error::404`.

If you wish to provide an alternate response status or use a canned response,
you should provide your own handler and pipe it to your application.

## Page not found

Error handlers work at the outermost layer, and are used to catch exceptions and
errors in your application. At the _innermost_ layer of your application, you
should ensure you have middleware that is _guaranteed_ to return a response;
this will prevent the default delegate from needing to execute by ensuring that
the middleware queue never fully depletes. This in turn allows you to fully
craft what sort of response is returned.

Generally speaking, reaching the innermost middleware layer indicates that no
middleware was capable of handling the request, and thus an HTTP 404 Not Found
condition.

To simplify such responses, we provide `Mezzio\Handler\NotFoundHandler`,
detailed int he above section.

You should pipe it as the innermost layer of your application:

```php
// A basic application:
$app->pipe(ErrorHandler::class);
$app->pipe(PathBasedRoutingMiddleware::class);
$app->pipe(DispatchMiddleware::class);
$app->pipe(NotFoundHandler::class);
```
