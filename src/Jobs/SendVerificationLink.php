<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\VerifyEmailMail;
use App\Repositories\UserRepository;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\EmailVerificationTokens;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;

/**
 * Reloads the account rather than trusting the push: by the time this runs
 * the address may be verified, or changed, and the link goes to the one the
 * account has now.
 */
final class SendVerificationLink implements JobInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerificationTokens $tokens,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth,
    ) {}

    public function handle(array $payload): void
    {
        $user = $this->users->byIdentifier((int) ($payload['user'] ?? 0));

        if (!$user instanceof User || $user->hasVerifiedEmail()) {
            return;
        }

        $link = rtrim($this->app->url, '/') . '/verify-email/' . $this->tokens->create($user);

        $this->mailer->send(VerifyEmailMail::to($user, $link, $this->auth->verifyTtl, $this->app->name));
    }
}
