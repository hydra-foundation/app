<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entities\User;
use Hydra\Mail\Message;

/** The message asking the new address to confirm an account's move to it. */
final class EmailChangeMail
{
    public static function to(string $email, User $user, string $link, int $ttl, string $appName): Message
    {
        $hours = intdiv($ttl, 3600);
        $within = $hours >= 1 ? "{$hours} hours" : intdiv($ttl, 60) . ' minutes';

        return Message::make()
            ->to($email, $user->username)
            ->subject("Confirm your new {$appName} email address")
            ->text(<<<TEXT
                Hi {$user->username},

                To move your account to this address, open this link within {$within}:

                {$link}

                Until then your account keeps {$user->email}. If you did not ask
                for this, you can ignore this email.
                TEXT);
    }
}
