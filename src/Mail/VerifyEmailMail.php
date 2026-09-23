<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entities\User;
use Hydra\Mail\Message;

/** The message carrying an email verification link. */
final class VerifyEmailMail
{
    public static function to(User $user, string $link, int $ttl, string $appName): Message
    {
        $hours = intdiv($ttl, 3600);
        $within = $hours >= 1 ? "{$hours} hours" : intdiv($ttl, 60) . ' minutes';

        return Message::make()
            ->to($user->email, $user->username)
            ->subject("Verify your {$appName} email address")
            ->text(<<<TEXT
                Hi {$user->username},

                To confirm this is your address, open this link within {$within}:

                {$link}

                If you did not ask for this, you can ignore this email.
                TEXT);
    }
}
