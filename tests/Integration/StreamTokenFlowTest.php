<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The page's way to a stream: ask for the topics it shows, and get a listen
 * token for the ones this user may hear. Nothing else hands out tokens, so
 * this is where who-hears-what is decided.
 */
#[CoversNothing]
final class StreamTokenFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->token('demo')->assertStatus(401);
    }

    public function test_a_permitted_topic_gets_a_stream_url_for_this_user_and_topic(): void
    {
        $id = $this->signIn();

        $response = $this->token('demo')->assertOk()->assertHeader('Cache-Control', 'no-store');
        $body = self::json($response);

        $this->assertIsString($body['url']);
        $this->assertStringStartsWith('/stream?token=', $body['url']);
        $grant = $this->app->get(StreamToken::class)->open(substr($body['url'], strlen('/stream?token=')));
        $this->assertNotNull($grant);
        $this->assertSame($id, $grant->userId);
        $this->assertSame(['demo'], $grant->topics);
        $this->assertSame($grant->expiresAt, $body['expires_at']);
    }

    public function test_several_topics_are_granted_together_once_each(): void
    {
        $this->signIn();
        $this->app->get(TopicPolicy::class)->allow('module.{slug}', static fn (): bool => true);

        $body = self::json($this->token('demo, module.users,demo')->assertOk());
        $grant = $this->app->get(StreamToken::class)->open(substr((string) $body['url'], strlen('/stream?token=')));

        $this->assertSame(['demo', 'module.users'], $grant?->topics);
    }

    public function test_a_topic_the_policy_does_not_permit_is_refused_by_name(): void
    {
        $this->signIn();

        $response = $this->token('demo,module.secrets')->assertStatus(403);

        $this->assertSame(['error' => 'You may not listen to module.secrets.'], self::json($response));
    }

    public function test_no_topics_is_refused(): void
    {
        $this->signIn();

        $this->assertSame(['error' => 'Name at least one topic.'], self::json($this->token('')->assertStatus(422)));
        $this->assertSame(['error' => 'Name at least one topic.'], self::json($this->request('/stream/token')->assertStatus(422)));
    }

    public function test_an_invalid_topic_is_refused(): void
    {
        $this->signIn();

        $this->assertSame(['error' => '"Module:Users" is not a valid topic.'], self::json($this->token('Module:Users')->assertStatus(422)));
    }

    public function test_more_than_thirty_two_topics_is_refused(): void
    {
        $this->signIn();
        $this->app->get(TopicPolicy::class)->allow('{n}', static fn (): bool => true);

        $topics = implode(',', array_map(static fn (int $i): string => "t{$i}", range(1, 33)));

        $this->assertSame(['error' => 'Ask for at most 32 topics.'], self::json($this->token($topics)->assertStatus(422)));
    }

    public function test_it_has_its_own_rate_limit(): void
    {
        $this->signIn();

        for ($i = 0; $i < 60; $i++) {
            $this->token('demo')->assertOk();
        }

        $this->token('demo')->assertStatus(429);
    }

    private function signIn(): int
    {
        $id = $this->app->seed('listener');
        $this->app->login('listener')->assertStatus(302);

        return $id;
    }

    private function token(string $topics): TestResponse
    {
        return $this->request('/stream/token?topics=' . rawurlencode($topics));
    }

    private function request(string $uri): TestResponse
    {
        return $this->app->http()->get($uri, ['Accept' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private static function json(TestResponse $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
