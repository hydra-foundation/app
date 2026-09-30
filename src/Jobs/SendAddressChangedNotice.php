<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\AppConfig;
use App\Entities\User;
use App\Mail\AddressChangedMail;
use App\Repositories\UserRepository;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Queue\Contracts\JobInterface;

/**
 * Carries the old address in the payload, since the account no longer holds
 * it, and names the new one as the account has it when this runs.
 */
final class SendAddressChangedNotice implements JobInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly AppConfig $app,
        private readonly Notifier $notifier,
    ) {}

    public function handle(array $payload): void
    {
        $user = $this->users->byIdentifier((int) ($payload['user'] ?? 0));
        $previous = (string) ($payload['previous'] ?? '');

        if (!$user instanceof User || $previous === '') {
            return;
        }

        $this->mailer->send(AddressChangedMail::to($previous, $user, $this->app->name));

        $this->notifier->notify($user->id, new Notice(
            'Your email address changed',
            "Mail now goes to {$user->email}. The old address was told too.",
            '/admin/settings/account',
            'account.email_changed',
        ));
    }
}
