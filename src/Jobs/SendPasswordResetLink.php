<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\PasswordResetMail;
use App\Repositories\UserRepository;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\Events\PasswordResetLinkSent;
use Hydra\Auth\PasswordResetTokens;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Queued for every address the form accepts, known or not, so the request
 * does the same work either way and its timing says nothing about which.
 * The account is looked up here, and the token made here: the payload holds
 * no link, so none sits in the jobs table.
 */
final class SendPasswordResetLink implements JobInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokens $tokens,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth,
        private readonly EventDispatcherInterface $events,
    ) {}

    public function handle(array $payload): void
    {
        $user = $this->users->byEmail((string) ($payload['email'] ?? ''));

        if (!$user instanceof User) {
            return;
        }

        $link = rtrim($this->app->url, '/') . '/reset-password/' . $this->tokens->create($user);

        $this->mailer->send(PasswordResetMail::to($user, $link, $this->auth->resetTtl, $this->app->name));
        $this->events->dispatch(new PasswordResetLinkSent($user));
    }
}
