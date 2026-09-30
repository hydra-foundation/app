<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\TwoFactorMail;
use App\Repositories\UserRepository;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;
use InvalidArgumentException;

final class SendTwoFactorNotice implements JobInterface
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RECOVERY_CODE_USED = 'recovery_code_used';

    /** Where each notice sends the reader: the screen that turns two-factor on and off. */
    private const SECURITY = '/admin/settings/security';

    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
        private readonly Notifier $notifier,
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

        // The same news on the bell, where the account's owner will see it
        // next time they are signed in, whoever reads their mail.
        $this->notifier->notify($user->id, match ($payload['notice']) {
            self::ENABLED => new Notice(
                'Two-factor authentication turned on',
                'Signing in now also asks for a code from your authenticator app.',
                self::SECURITY,
                'two_factor.enabled',
            ),
            self::DISABLED => new Notice(
                'Two-factor authentication turned off',
                'Signing in asks only for your password now. If this was not you, change your password.',
                self::SECURITY,
                'two_factor.disabled',
            ),
            default => new Notice(
                'A recovery code was used',
                ($left = (int) ($payload['remaining'] ?? 0)) === 1 ? '1 recovery code left.' : "{$left} recovery codes left.",
                self::SECURITY,
                'two_factor.recovery_code_used',
            ),
        });
    }
}
