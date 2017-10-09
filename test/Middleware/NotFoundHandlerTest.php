<?php
/**
 * @see       https://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2016-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Expressive\Middleware;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use Webimpress\HttpMiddlewareCompatibility\HandlerInterface as DelegateInterface;
use Webimpress\HttpMiddlewareCompatibility\MiddlewareInterface;
use Zend\Expressive\Delegate\NotFoundDelegate;
use Zend\Expressive\Middleware\NotFoundHandler;

use const Webimpress\HttpMiddlewareCompatibility\HANDLER_METHOD;

class NotFoundHandlerTest extends TestCase
{
    /** @var NotFoundDelegate|ObjectProphecy */
    private $internal;

    /** @var ServerRequestInterface|ObjectProphecy */
    private $request;

    /** @var DelegateInterface|ObjectProphecy */
    private $delegate;

    public function setUp()
    {
        $this->internal = $this->prophesize(NotFoundDelegate::class);
        $this->request  = $this->prophesize(ServerRequestInterface::class);

        $this->delegate = $this->prophesize(DelegateInterface::class);
        $this->delegate->{HANDLER_METHOD}(Argument::type(ServerRequestInterface::class))->shouldNotBeCalled();
    }

    public function testImplementsInteropMiddleware()
    {
        $handler = new NotFoundHandler($this->internal->reveal());
        $this->assertInstanceOf(MiddlewareInterface::class, $handler);
    }

    public function testProxiesToInternalDelegate()
    {
        $this->internal
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->willReturn('CONTENT');

        $handler = new NotFoundHandler($this->internal->reveal());
        $this->assertEquals('CONTENT', $handler->process($this->request->reveal(), $this->delegate->reveal()));
    }
}
