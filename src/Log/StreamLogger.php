<?php

declare(strict_types=1);

namespace App\Log;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * A minimal PSR-3 logger that writes one plain-text line per record to a
 * writable stream.
 *
 * Sink selection (a file, php://stderr, …) is the caller's concern: this class
 * is handed an already-open stream and only formats and writes. A failure to
 * write is swallowed — a broken logging sink must never escalate into an
 * application failure.
 *
 * Extending {@see AbstractLogger} gives us the eight level shortcuts
 * (error(), info(), …); we implement only log().
 */
final class StreamLogger extends AbstractLogger
{
    /** @param resource $stream An open, writable stream. */
    public function __construct(private $stream)
    {
    }

    /**
     * @param mixed             $level
     * @param string|Stringable $message
     * @param array<mixed>      $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date(DATE_ATOM),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            $this->renderContext($context),
        );

        if (is_resource($this->stream)) {
            @fwrite($this->stream, $line);
        }
    }

    /**
     * Replace {placeholders} with their context values per PSR-3.
     *
     * Only scalars and Stringables are substituted, and substitution is keyed
     * on the actual value type — not truthiness — so 0, '' and false render
     * rather than vanish. Placeholders without a usable value are left intact.
     *
     * @param array<mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';

            if (!str_contains($message, $placeholder)) {
                continue;
            }

            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements[$placeholder] = is_bool($value)
                    ? ($value ? 'true' : 'false')
                    : (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Render leftover context as a trailing fragment. A Throwable under the
     * conventional 'exception' key is rendered as a readable trace; everything
     * else is JSON-encoded.
     *
     * @param array<mixed> $context
     */
    private function renderContext(array $context): string
    {
        $fragment = '';

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $fragment .= ' ' . $this->renderException($context['exception']);
            unset($context['exception']);
        }

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json !== false) {
                $fragment .= ' ' . $json;
            }
        }

        return $fragment;
    }

    private function renderException(Throwable $e): string
    {
        return sprintf(
            '%s: %s in %s:%d%s%s',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString(),
        );
    }
}
