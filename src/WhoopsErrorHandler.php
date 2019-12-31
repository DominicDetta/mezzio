<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

namespace Mezzio;

use Laminas\Stratigility\Http\Request as StratigilityRequest;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run as Whoops;

/**
 * Final handler with templated page capabilities plus Whoops exception reporting.
 *
 * Extends from TemplatedErrorHandler in order to provide templated error and 404
 * pages; for exceptions, it delegates to Whoops to provide a user-friendly
 * interface for navigating an exception stack trace.
 *
 * @see http://filp.github.io/whoops/
 */
class WhoopsErrorHandler extends TemplatedErrorHandler
{
    /**
     * Whoops runner instance to use when returning exception details.
     *
     * @var Whoops
     */
    private $whoops;

    /**
     * Whoops PrettyPageHandler; injected to allow runtime configuration with
     * request information.
     *
     * @var PrettyPageHandler
     */
    private $whoopsHandler;

    /**
     * @param Whoops $whoops
     * @param PrettyPageHandler $whoopsHandler
     * @param null|Template\TemplateRendererInterface $renderer
     * @param null|string $template404
     * @param null|string $templateError
     * @param null|Response $originalResponse
     */
    public function __construct(
        Whoops $whoops,
        PrettyPageHandler $whoopsHandler = null,
        Template\TemplateRendererInterface $renderer = null,
        $template404 = 'error/404',
        $templateError = 'error/error',
        Response $originalResponse = null
    ) {
        $this->whoops        = $whoops;
        $this->whoopsHandler = $whoopsHandler;
        parent::__construct($renderer, $template404, $templateError, $originalResponse);
    }

    /**
     * Handle an exception.
     *
     * Calls on prepareWhoopsHandler() to inject additional data tables into
     * the generated payload, and then injects the response with the result
     * of whoops handling the exception.
     *
     * @param \Throwable $exception
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function handleException($exception, Request $request, Response $response)
    {
        // Push the whoops handler if any
        if ($this->whoopsHandler !== null) {
            $this->whoops->pushHandler($this->whoopsHandler);
        }

        // Walk through all handlers
        foreach ($this->whoops->getHandlers() as $handler) {
            // Add fancy data for the PrettyPageHandler
            if ($handler instanceof PrettyPageHandler) {
                $this->prepareWhoopsHandler($request, $handler);
            }
        }

        $response
            ->getBody()
            ->write($this->whoops->handleException($exception));

        return $response;
    }

    /**
     * Prepare the Whoops page handler with a table displaying request information
     *
     * @param Request           $request
     * @param PrettyPageHandler $handler
     */
    private function prepareWhoopsHandler(Request $request, PrettyPageHandler $handler)
    {
        if ($request instanceof StratigilityRequest) {
            $request = $request->getOriginalRequest();
        }

        $uri = $request->getUri();
        $handler->addDataTable('Mezzio Application Request', [
            'HTTP Method'            => $request->getMethod(),
            'URI'                    => (string) $uri,
            'Script'                 => $request->getServerParams()['SCRIPT_NAME'],
            'Headers'                => $request->getHeaders(),
            'Cookies'                => $request->getCookieParams(),
            'Attributes'             => $request->getAttributes(),
            'Query String Arguments' => $request->getQueryParams(),
            'Body Params'            => $request->getParsedBody(),
        ]);
    }
}
