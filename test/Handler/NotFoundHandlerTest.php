<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Handler;

use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Mezzio\Handler\NotFoundHandler;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class NotFoundHandlerTest extends TestCase
{
    /** @var ResponseInterface|ObjectProphecy */
    private $response;

    protected function setUp()
    {
        $this->response = $this->prophesize(ResponseInterface::class);
    }

    public function testConstructorDoesNotRequireARenderer()
    {
        $handler = new NotFoundHandler($this->response->reveal());
        $this->assertInstanceOf(NotFoundHandler::class, $handler);
        $this->assertAttributeSame($this->response->reveal(), 'responsePrototype', $handler);
    }

    public function testConstructorCanAcceptRendererAndTemplate()
    {
        $renderer = $this->prophesize(TemplateRendererInterface::class)->reveal();
        $template = 'foo::bar';
        $layout = 'layout::error';

        $handler = new NotFoundHandler($this->response->reveal(), $renderer, $template, $layout);

        $this->assertInstanceOf(NotFoundHandler::class, $handler);
        $this->assertAttributeSame($renderer, 'renderer', $handler);
        $this->assertAttributeEquals($template, 'template', $handler);
        $this->assertAttributeEquals($layout, 'layout', $handler);
    }

    public function testRendersDefault404ResponseWhenNoRendererPresent()
    {
        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getMethod()->willReturn(RequestMethod::METHOD_POST);
        $request->getUri()->willReturn('https://example.com/foo/bar');

        $stream = $this->prophesize(StreamInterface::class);
        $stream->write('Cannot POST https://example.com/foo/bar')->shouldBeCalled();
        $this->response->withStatus(StatusCode::STATUS_NOT_FOUND)->will([$this->response, 'reveal']);
        $this->response->getBody()->will([$stream, 'reveal']);

        $handler = new NotFoundHandler($this->response->reveal());

        $response = $handler->handle($request->reveal());

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testUsesRendererToGenerateResponseContentsWhenPresent()
    {
        $request = $this->prophesize(ServerRequestInterface::class)->reveal();

        $renderer = $this->prophesize(TemplateRendererInterface::class);
        $renderer
            ->render(
                NotFoundHandler::TEMPLATE_DEFAULT,
                [
                    'request' => $request,
                    'layout' => NotFoundHandler::LAYOUT_DEFAULT,
                ]
            )
            ->willReturn('CONTENT');

        $stream = $this->prophesize(StreamInterface::class);
        $stream->write('CONTENT')->shouldBeCalled();

        $this->response->withStatus(StatusCode::STATUS_NOT_FOUND)->will([$this->response, 'reveal']);
        $this->response->getBody()->will([$stream, 'reveal']);

        $handler = new NotFoundHandler($this->response->reveal(), $renderer->reveal());

        $response = $handler->handle($request);

        $this->assertSame($this->response->reveal(), $response);
    }
}
