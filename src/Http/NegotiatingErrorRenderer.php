<?php

declare(strict_types=1);

namespace App\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\Htmx;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;

/**
 * Error presentation
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
     * An HTML fragment htmx swaps into the layout's error region rather than the
     * element the failed request came from. Out-of-band, so that element keeps
     * whatever the reader was looking at.
     */
    private function htmxFragment(ErrorContext $context): ResponseInterface
    {
        $response = $this->responder->html($this->errorMarkup($context), $context->status);

        return $this->responder->htmx()
            ->retarget(self::HTMX_ERROR_TARGET, 'innerHTML')
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
