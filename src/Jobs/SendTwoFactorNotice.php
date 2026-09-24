<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\TwoFactorMail;
use App\Repositories\UserRepository;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;
use InvalidArgumentException;

final class SendTwoFactorNotice implements JobInterface
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RECOVERY_CODE_USED = 'recovery_code_used';

    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
    ) {}

    public function handle(array $payload): void
    {
        $user = $this->users->byIdentifier((int) ($payload['user'] ?? 0));

        if (!$user instanceof User) {
            return;
        }

        $this->mailer->send(match ($payload['notice'] ?? null) {
            self::ENABLED => TwoFactorMail::enabled($user, $this->app->name),
            self::DISABLED => TwoFactorMail::disabled($user, $this->app->name),
            self::RECOVERY_CODE_USED => TwoFactorMail::recoveryCodeUsed($user, $this->app->name, (int) ($payload['remaining'] ?? 0)),
            default => throw new InvalidArgumentException('Unknown two-factor notice: ' . var_export($payload['notice'] ?? null, true)),
        });
    }
}
