<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entities\User;
use Hydra\Mail\Message;

/** The warning sent to the address an account had before it was changed. */
final class AddressChangedMail
{
    public static function to(string $previous, User $user, string $appName): Message
    {
        return Message::make()
            ->to($previous, $user->username)
            ->subject("Your {$appName} email address was changed")
            ->text(<<<TEXT
                Hi {$user->username},

                The email address on your {$appName} account was changed from
                {$previous} to {$user->email}.

                If you made this change, there is nothing to do.

                If you did not, contact an administrator. A password reset
                would now go to the new address, not to this one.
                TEXT);
    }
}
