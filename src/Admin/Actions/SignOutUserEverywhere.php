<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Auth\SignOutEverywhere;
use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Auth\Contracts\UserProviderInterface;

/** Sign out everywhere, from the user's row under Users. */
final class SignOutUserEverywhere implements RowActionInterface
{
    public function __construct(
        private readonly UserProviderInterface $users,
        private readonly SignOutEverywhere $signOut,
    ) {}

    public function run(string $id): string
    {
        $user = ctype_digit($id) ? $this->users->byIdentifier((int) $id) : null;

        return ($this->signOut)($user ?? throw WriteRejected::on('id', 'That user no longer exists.'));
    }
}
