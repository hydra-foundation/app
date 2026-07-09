<?php

declare(strict_types=1);

namespace App\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\Htmx;
use Hydra\Http\HtmxResponse;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;

/**
 * The app's error presentation policy: pick a representation from the request.
 *
 * This is the "app owns the noun" half of the pluggable error renderer — the
 * framework ships the {@see ErrorRendererInterface} seam and a plain-text
 * default; this class is where THIS app decides it also speaks htmx fragments,
 * JSON, and HTML. The negotiation is explicit and lives here, not auto-detected
 * in a package.
 *
 * Order matters: an htmx request wins first (htmx sets Accept: text/html too, so
 * checking it before the HTML branch is what distinguishes a fragment swap from
 * a full page). Then JSON, then HTML, then the plain-text default for anything
 * else (curl, health checks). The client-facing text always comes from
 * {@see ErrorContext::clientMessage()} so an internal message can't leak; debug
 * detail is added only when {@see ErrorContext::$debug} is set.
 */
final class NegotiatingErrorRenderer implements ErrorRendererInterface
{
    /** The htmx error fragment is swapped into this fixed region of the layout. */
    private const HTMX_ERROR_TARGET = '#app-error';

    public function __construct(
        private readonly Responder $responder,
        private readonly PlainTextErrorRenderer $text,
    ) {}

    public function render(ErrorContext $context): ResponseInterface
    {
        $htmx = Htmx::fromRequest($context->request);

        if ($htmx->isHtmx()) {
            return $this->htmxFragment($context);
        }

        $accept = $context->request->getHeaderLine('Accept');

        if (str_contains($accept, 'application/json')) {
            return $this->json($context);
        }

        if (str_contains($accept, 'text/html')) {
            return $this->htmlPage($context);
        }

        // curl, health checks, anything that didn't ask for html/json.
        return $this->text->render($context);
    }

    /**
     * An HTML fragment htmx can swap into the layout's error region, retargeted
     * so a failed request doesn't blow away the element it originated from.
     */
    private function htmxFragment(ErrorContext $context): ResponseInterface
    {
        $response = $this->responder->html($this->errorMarkup($context), $context->status);

        return (new HtmxResponse)
            ->retarget(self::HTMX_ERROR_TARGET)
            ->reswap('innerHTML')
            ->applyTo($response);
    }

    private function json(ErrorContext $context): ResponseInterface
    {
        $payload = ['error' => $context->clientMessage(), 'status' => $context->status];

        if ($context->debug) {
            $payload['exception'] = $context->error::class;
            $payload['message'] = $context->error->getMessage();
        }

        return $this->responder->json($payload, $context->status);
    }

    private function htmlPage(ErrorContext $context): ResponseInterface
    {
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>' . $context->status . '</title></head><body>'
            . $this->errorMarkup($context)
            . '</body></html>';

        return $this->responder->html($body, $context->status);
    }

    /** The shared inner markup for both the full page and the htmx fragment. */
    private function errorMarkup(ErrorContext $context): string
    {
        $markup = '<h1>' . $this->e((string) $context->status) . '</h1>'
            . '<p>' . $this->e($context->clientMessage()) . '</p>';

        if ($context->debug) {
            $markup .= '<pre>' . $this->e(
                $context->error::class . ': ' . $context->error->getMessage()
                . "\nin " . $context->error->getFile() . ':' . $context->error->getLine()
                . "\n\n" . $context->error->getTraceAsString()
            ) . '</pre>';
        }

        return $markup;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
