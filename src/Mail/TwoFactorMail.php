<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entities\User;
use Hydra\Mail\Message;

/** The notices sent when an account's second factor changes or is bypassed. */
final class TwoFactorMail
{
    public static function enabled(User $user, string $appName): Message
    {
        return self::message($user, "Two-factor sign in is on for your {$appName} account", <<<TEXT
            Two-factor sign in was turned on for your {$appName} account.
            Signing in now takes a code from your authenticator app as well
            as your password.
            TEXT);
    }

    public static function disabled(User $user, string $appName): Message
    {
        return self::message($user, "Two-factor sign in is off for your {$appName} account", <<<TEXT
            Two-factor sign in was turned off for your {$appName} account.
            Your password alone now signs you in.
            TEXT);
    }

    public static function recoveryCodeUsed(User $user, string $appName, int $remaining): Message
    {
        $left = match ($remaining) {
            0 => 'That was your last one: generate new codes in Settings, under Security.',
            1 => 'You have 1 recovery code left.',
            default => "You have {$remaining} recovery codes left.",
        };

        return self::message($user, "A recovery code was used on your {$appName} account", <<<TEXT
            A recovery code was used to sign in to your {$appName} account.
            {$left}
            TEXT);
    }

    private static function message(User $user, string $subject, string $what): Message
    {
        return Message::make()
            ->to($user->email, $user->username)
            ->subject($subject)
            ->text(<<<TEXT
                Hi {$user->username},

                {$what}

                If this was not you, change your password and contact an
                administrator.
                TEXT);
    }
}
