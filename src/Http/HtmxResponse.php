<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Fluent builder for the HX-* response headers that drive htmx from the server.
 *
 * Collect directives, then stamp them onto a PSR-7 response with applyTo():
 *
 *   return (new HtmxResponse)
 *       ->trigger('cartUpdated')
 *       ->pushUrl('/cart')
 *       ->applyTo($this->respond->html($body));
 *
 * The value over raw withHeader() is HX-Trigger encoding: htmx accepts a plain
 * (comma-separated) event list only while no event carries a detail; the moment
 * one does, the whole header must become a JSON object. This handles that.
 */
final class HtmxResponse
{
    /** @var array<string, string> Single-value directive headers. */
    private array $headers = [];

    /** @var array<string, mixed> event name => detail (null = no detail). */
    private array $triggers = [];

    /**
     * Full client-side redirect (browser navigates, no swap). Mutually
     * exclusive with location() — htmx honours only one, so set just one.
     */
    public function redirect(string $url): self
    {
        return $this->set('HX-Redirect', $url);
    }

    /**
     * Client-side navigation done as an ajax swap rather than a full load.
     * Mutually exclusive with redirect() — set one or the other, not both.
     */
    public function location(string $url): self
    {
        return $this->set('HX-Location', $url);
    }

    /** Push a new URL into browser history. */
    public function pushUrl(string $url): self
    {
        return $this->set('HX-Push-Url', $url);
    }

    /** Replace the current URL in browser history. */
    public function replaceUrl(string $url): self
    {
        return $this->set('HX-Replace-Url', $url);
    }

    /** Tell the client to do a full page refresh. */
    public function refresh(): self
    {
        return $this->set('HX-Refresh', 'true');
    }

    /** Swap the response into a different element than the triggering one. */
    public function retarget(string $selector): self
    {
        return $this->set('HX-Retarget', $selector);
    }

    /** Override how the response is swapped in (e.g. "outerHTML", "beforeend"). */
    public function reswap(string $spec): self
    {
        return $this->set('HX-Reswap', $spec);
    }

    /** Choose which part of the response is swapped in. */
    public function reselect(string $selector): self
    {
        return $this->set('HX-Reselect', $selector);
    }

    /**
     * Trigger a client-side event. Call repeatedly to trigger several; pass a
     * $detail to send data with the event (forces the JSON encoding).
     */
    public function trigger(string $event, mixed $detail = null): self
    {
        $this->triggers[$event] = $detail;

        return $this;
    }

    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if ($this->triggers !== []) {
            $response = $response->withHeader('HX-Trigger', $this->encodeTriggers());
        }

        return $response;
    }

    private function set(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    private function encodeTriggers(): string
    {
        // Plain comma list while every event is detail-less; JSON once any
        // event carries data — htmx can't read a mixed plain/JSON header.
        $hasDetail = array_filter($this->triggers, static fn ($detail) => $detail !== null) !== [];

        if (!$hasDetail) {
            return implode(', ', array_keys($this->triggers));
        }

        return json_encode($this->triggers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
