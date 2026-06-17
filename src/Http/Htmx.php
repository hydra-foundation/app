<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader for the HX-* request headers htmx sends.
 *
 * Built from the request in a controller — `Htmx::fromRequest($request)` — so a
 * handler can branch on htmx state without juggling stringly header names. An
 * absent header reads as null (not sent), kept distinct from an empty value.
 */
final class Htmx
{
    public function __construct(private readonly ServerRequestInterface $request) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self($request);
    }

    /** True for any request htmx issued (htmx sends the literal "true"). */
    public function isHtmx(): bool
    {
        return $this->request->getHeaderLine('HX-Request') === 'true';
    }

    /** True when the request came from an hx-boost'd link or form. */
    public function isBoosted(): bool
    {
        return $this->request->getHeaderLine('HX-Boosted') === 'true';
    }

    /** The id of the target element (HX-Target), or null if not sent. */
    public function target(): ?string
    {
        return $this->header('HX-Target');
    }

    /** The id of the element that triggered the request (HX-Trigger), or null. */
    public function trigger(): ?string
    {
        return $this->header('HX-Trigger');
    }

    /** The name of the triggering element (HX-Trigger-Name), or null. */
    public function triggerName(): ?string
    {
        return $this->header('HX-Trigger-Name');
    }

    /** The user's response to an hx-prompt (HX-Prompt), or null. */
    public function prompt(): ?string
    {
        return $this->header('HX-Prompt');
    }

    /** The browser's current URL at request time (HX-Current-URL), or null. */
    public function currentUrl(): ?string
    {
        return $this->header('HX-Current-URL');
    }

    private function header(string $name): ?string
    {
        return $this->request->hasHeader($name) ? $this->request->getHeaderLine($name) : null;
    }
}
