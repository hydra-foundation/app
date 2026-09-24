<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\EmailChangeMail;
use App\Repositories\UserRepository;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\EmailChangeTokens;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;

/** Sent to the address the account wants to move to, never to the one it has. */
final class SendEmailChangeLink implements JobInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailChangeTokens $tokens,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth,
    ) {}

    public function handle(array $payload): void
    {
        $user = $this->users->byIdentifier((int) ($payload['user'] ?? 0));
        $email = (string) ($payload['email'] ?? '');

        if (!$user instanceof User || $email === '' || $email === $user->email) {
            return;
        }

        $link = rtrim($this->app->url, '/') . '/change-email/' . $this->tokens->create($user, $email);

        $this->mailer->send(EmailChangeMail::to($email, $user, $link, $this->auth->verifyTtl, $this->app->name));
    }
}
