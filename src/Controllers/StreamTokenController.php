<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\User;
use App\Http\Middleware\StreamTokenThrottleMiddleware;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\Topic;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hands a page a listen token for the topics it shows, and only those this
 * user may hear. The stream client in app.js asks here on load and whenever
 * its token runs out, so the token is never written into a page.
 */
final class StreamTokenController
{
    public function __construct(
        private readonly Responder $respond,
        private readonly GuardInterface $guard,
        private readonly StreamToken $tokens,
        private readonly TopicPolicy $policy,
        private readonly HubConfig $hub,
    ) {}

    #[Route('/stream/token', middleware: [StreamTokenThrottleMiddleware::class, AuthenticateMiddleware::class])]
    public function issue(Request $request): Response
    {
        $user = $this->guard->user();
        assert($user instanceof User);

        $asked = $request->getQueryParams()['topics'] ?? '';
        $topics = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', is_string($asked) ? $asked : '')),
            static fn (string $topic): bool => $topic !== '',
        )));

        if ($topics === []) {
            return $this->refuse('Name at least one topic.', 422);
        }

        if (count($topics) > StreamToken::MAX_TOPICS) {
            return $this->refuse(sprintf('Ask for at most %d topics.', StreamToken::MAX_TOPICS), 422);
        }

        foreach ($topics as $topic) {
            if (!Topic::isValid($topic)) {
                return $this->refuse("\"{$topic}\" is not a valid topic.", 422);
            }

            if (!$this->policy->permits($user->id, $topic)) {
                return $this->refuse("You may not listen to {$topic}.", 403);
            }
        }

        $token = $this->tokens->mint($user->id, $topics, $this->hub->tokenTtl);

        return $this->respond->json([
            'url' => '/stream?token=' . $token,
            'expires_at' => $this->tokens->open($token)?->expiresAt,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function refuse(string $error, int $status): Response
    {
        return $this->respond->json(['error' => $error], $status)->withHeader('Cache-Control', 'no-store');
    }
}
