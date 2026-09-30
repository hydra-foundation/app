<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Config\AppConfig;
use App\Entities\User;
use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Message;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Whether mail works, asked from above the Mail table: one message to the
 * admin who pressed it, sent in the request rather than queued, so a transport
 * that refuses says why on the spot instead of in Failed jobs a minute later.
 * One that works shows up at the top of the log, like any other.
 */
final class SendTestEmail implements ModuleActionInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GuardInterface $guard,
        private readonly AppConfig $app,
        private readonly ClockInterface $clock,
    ) {}

    public function run(): string
    {
        $user = $this->guard->user();

        if (!$user instanceof User || $user->email === '') {
            throw WriteRejected::on('email', 'Your account has no email address to send a test to.');
        }

        try {
            $this->mailer->send(
                Message::make()
                    ->to($user->email, $user->username)
                    ->subject("Test email from {$this->app->name}")
                    ->text(sprintf(
                        "This is a test email from %s, sent by %s from Administration › Mail at %s.\n\nIf you are reading it, mail is working.\n",
                        $this->app->name,
                        $user->username,
                        $this->clock->now()->format('Y-m-d H:i:s T'),
                    )),
            );
        } catch (TransportException | InvalidArgumentException $e) {
            // The transport's own words: they name the step that failed, never
            // the credentials sent at it.
            throw WriteRejected::on('email', $e->getMessage());
        }

        return "Test email sent to {$user->email}.";
    }
}
