<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Mezzio\Middleware\DispatchMiddleware;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DispatchMiddlewareTest extends TestCase
{
    /** @var ContainerInterface|ObjectProphecy */
    private $container;

    /** @var DelegateInterface|ObjectProphecy */
    private $delegate;

    /** @var DispatchMiddleware */
    private $middleware;

    /** @var ServerRequestInterface|ObjectProphecy */
    private $request;

    /** @var ResponseInterface|ObjectProphecy */
    private $responsePrototype;

    public function setUp()
    {
        $this->container         = $this->prophesize(ContainerInterface::class);
        $this->responsePrototype = $this->prophesize(ResponseInterface::class);
        $this->router            = $this->prophesize(RouterInterface::class);
        $this->request           = $this->prophesize(ServerRequestInterface::class);
        $this->delegate          = $this->prophesize(DelegateInterface::class);
        $this->middleware        = new DispatchMiddleware(
            $this->router->reveal(),
            $this->responsePrototype->reveal(),
            $this->container->reveal()
        );
    }

    public function testInvokesDelegateIfRequestDoesNotContainRouteResult()
    {
        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->request->getAttribute(RouteResult::class, false)->willReturn(false);
        $this->delegate->process($this->request->reveal())->willReturn($expected);

        $response = $this->middleware->process($this->request->reveal(), $this->delegate->reveal());

        $this->assertSame($expected, $response);
    }

    public function testInvokesMatchedMiddlewareWhenRouteResult()
    {
        $this->delegate->process()->shouldNotBeCalled();

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $routedMiddleware = $this->prophesize(ServerMiddlewareInterface::class);
        $routedMiddleware
            ->process($this->request->reveal(), $this->delegate->reveal())
            ->willReturn($expected);

        $routeResult = RouteResult::fromRoute(new Route('/', $routedMiddleware->reveal()));

        $this->request->getAttribute(RouteResult::class, false)->willReturn($routeResult);

        $response = $this->middleware->process($this->request->reveal(), $this->delegate->reveal());

        $this->assertSame($expected, $response);
    }

    /**
     * @group 453
     */
    public function testCanDispatchCallableDoublePassMiddleware()
    {
        $this->delegate->process()->shouldNotBeCalled();

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $routedMiddleware = $this->prophesize(ServerMiddlewareInterface::class);
        $routedMiddleware
            ->process(Argument::that([$this->request, 'reveal']), Argument::that([$this->delegate, 'reveal']))
            ->willReturn($expected);

        $routeResult = RouteResult::fromRoute(new Route('/', $routedMiddleware->reveal()));

        $this->request->getAttribute(RouteResult::class, false)->willReturn($routeResult);

        $response = $this->middleware->process($this->request->reveal(), $this->delegate->reveal());

        $this->assertSame($expected, $response);
    }

    /**
     * @group 453
     */
    public function testCanDispatchLazyMiddleware()
    {
        $this->delegate->process()->shouldNotBeCalled();

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $routedMiddleware = $this->prophesize(ServerMiddlewareInterface::class);
        $routedMiddleware
            ->process(Argument::that([$this->request, 'reveal']), Argument::that([$this->delegate, 'reveal']))
            ->willReturn($expected);

        $this->container->has('RoutedMiddleware')->willReturn(true);
        $this->container->get('RoutedMiddleware')->willReturn($routedMiddleware->reveal());

        // Since 2.0, we never have service names in routes, only lazy-loading middleware
        $lazyMiddleware = new LazyLoadingMiddleware(
            $this->container->reveal(),
            $this->responsePrototype->reveal(),
            'RoutedMiddleware'
        );

        $routeResult = RouteResult::fromRoute(new Route('/', $lazyMiddleware));

        $this->request->getAttribute(RouteResult::class, false)->willReturn($routeResult);

        $response = $this->middleware->process($this->request->reveal(), $this->delegate->reveal());

        $this->assertSame($expected, $response);
    }
}
