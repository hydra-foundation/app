<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entities\User;
use Hydra\Mail\Message;

/** The message carrying a password reset link. Plain text, so no client rewrites the link. */
final class PasswordResetMail
{
    public static function to(User $user, string $link, int $ttl, string $appName): Message
    {
        $minutes = intdiv($ttl, 60);

        return Message::make()
            ->to($user->email, $user->username)
            ->subject("Reset your {$appName} password")
            ->text(<<<TEXT
                Hi {$user->username},

                Someone asked to reset the password for your {$appName} account.
                To choose a new one, open this link within {$minutes} minutes:

                {$link}

                The link works once. If you did not ask for this, ignore this
                email and your password stays as it is.
                TEXT);
    }
}
