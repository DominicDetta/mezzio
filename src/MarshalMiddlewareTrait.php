<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio;

use Interop\Http\Server\MiddlewareInterface;
use Laminas\Stratigility\Middleware\CallableMiddlewareDecorator;
use Laminas\Stratigility\Middleware\DoublePassMiddlewareDecorator;
use Laminas\Stratigility\MiddlewarePipe;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Trait defining methods for verifying and/or generating middleware to pipe to
 * an application.
 */
trait MarshalMiddlewareTrait
{
    use IsCallableInteropMiddlewareTrait;

    /**
     * Prepare middleware for piping.
     *
     * Performs a number of checks on $middleware to prepare it for piping
     * to the application:
     *
     * - If it's callable, it's returned immediately.
     * - If it's a non-callable array, it's passed to marshalMiddlewarePipe().
     * - If it's a string service name, it's passed to marshalLazyMiddlewareService().
     * - If it's a string class name, it's passed to marshalInvokableMiddleware().
     * - If no callable is created, an exception is thrown.
     *
     * @param mixed $middleware
     * @throws Exception\InvalidMiddlewareException
     */
    private function prepareMiddleware(
        $middleware,
        Router\RouterInterface $router,
        ResponseInterface $responsePrototype,
        ContainerInterface $container = null
    ) : MiddlewareInterface {
        if ($middleware === Application::ROUTING_MIDDLEWARE) {
            return new Middleware\RouteMiddleware($router, $responsePrototype);
        }

        if ($middleware === Application::DISPATCH_MIDDLEWARE) {
            return new Middleware\DispatchMiddleware($router, $responsePrototype, $container);
        }

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($this->isCallableInteropMiddleware($middleware)) {
            return new CallableMiddlewareDecorator($middleware);
        }

        if ($this->isCallable($middleware)) {
            return new DoublePassMiddlewareDecorator($middleware, $responsePrototype);
        }

        if (is_array($middleware)) {
            return $this->marshalMiddlewarePipe($middleware, $router, $responsePrototype, $container);
        }

        if (is_string($middleware) && $container && $container->has($middleware)) {
            return new Middleware\LazyLoadingMiddleware($container, $responsePrototype, $middleware);
        }

        if (is_string($middleware)) {
            return $this->marshalInvokableMiddleware($middleware, $responsePrototype);
        }

        throw new Exception\InvalidMiddlewareException(sprintf(
            'Unable to resolve middleware "%s" to a callable or %s',
            is_object($middleware) ? get_class($middleware) . '[Object]' : gettype($middleware) . '[Scalar]',
            MiddlewareInterface::class
        ));
    }

    /**
     * Marshal a middleware pipe from an array of middleware.
     *
     * Each item in the array can be one of the following:
     *
     * - A callable middleware
     * - A string service name of middleware to retrieve from the container
     * - A string class name of a constructor-less middleware class to
     *   instantiate
     *
     * As each middleware is verified, it is piped to the middleware pipe.
     *
     * @throws Exception\InvalidMiddlewareException for any invalid middleware items.
     */
    private function marshalMiddlewarePipe(
        array $middlewares,
        Router\RouterInterface $router,
        ResponseInterface $responsePrototype,
        ContainerInterface $container = null
    ) : MiddlewarePipe {
        $middlewarePipe = new MiddlewarePipe();

        foreach ($middlewares as $middleware) {
            $middlewarePipe->pipe(
                $this->prepareMiddleware($middleware, $router, $responsePrototype, $container)
            );
        }

        return $middlewarePipe;
    }

    /**
     * Attempt to instantiate the given middleware.
     *
     * @throws Exception\InvalidMiddlewareException if $middleware is not a class.
     * @throws Exception\InvalidMiddlewareException if $middleware does not resolve
     *     to either an invokable class or MiddlewareInterface instance.
     */
    private function marshalInvokableMiddleware(
        string $middleware,
        ResponseInterface $responsePrototype
    ) : MiddlewareInterface {
        if (! class_exists($middleware)) {
            throw new Exception\InvalidMiddlewareException(sprintf(
                'Unable to create middleware "%s"; not a valid class or service name',
                $middleware
            ));
        }

        $instance = new $middleware();

        if ($instance instanceof MiddlewareInterface) {
            return $instance;
        }

        if ($this->isCallableInteropMiddleware($instance)) {
            return new CallableMiddlewareDecorator($instance);
        }

        if (! is_callable($instance)) {
            throw new Exception\InvalidMiddlewareException(sprintf(
                'Middleware of class "%s" is invalid; neither invokable nor %s',
                $middleware,
                MiddlewareInterface::class
            ));
        }

        return new DoublePassMiddlewareDecorator($instance, $responsePrototype);
    }
}
