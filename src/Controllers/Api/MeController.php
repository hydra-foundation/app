<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Entities\User;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Attributes\RouteGroup;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;

/** Who the caller is: a bearer token's owner, or the signed-in browser. */
#[RouteGroup(prefix: '/api/v1', middleware: [AuthenticateMiddleware::class])]
final class MeController
{
    public function __construct(
        private readonly Responder $respond,
        private readonly GuardInterface $guard,
    ) {}

    #[Route('/me')]
    public function show(): Response
    {
        $user = $this->guard->user();
        assert($user instanceof User);

        return $this->respond->json([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
        ]);
    }
}
