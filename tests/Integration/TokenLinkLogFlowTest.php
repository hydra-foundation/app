<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A mailed link carries its token in the path, and the access log writes the
 * path down: the line it writes has the placeholder, never the token.
 */
#[CoversNothing]
final class TokenLinkLogFlowTest extends TestCase
{
    private const TOKEN = 'live-token-7f3a9c';

    public function test_no_link_writes_its_token_to_the_log(): void
    {
        $app = TestApp::boot();

        foreach (['/reset-password', '/verify-email', '/change-email'] as $prefix) {
            $app->http()->get($prefix . '/' . self::TOKEN)->assertRedirect($prefix);
        }

        $paths = array_column(array_column(array_filter(
            $app->log()->records(),
            static fn (array $record): bool => $record['message'] === 'request handled',
        ), 'context'), 'path');

        $this->assertContains('/reset-password/{token}', $paths);
        $this->assertContains('/verify-email/{token}', $paths);
        $this->assertContains('/change-email/{token}', $paths);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($app->log()->records(), JSON_THROW_ON_ERROR));
    }
}
