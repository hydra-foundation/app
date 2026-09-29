<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Auth\SignOutEverywhere;
use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/** Sign out everywhere, for whoever owns this sign-in under Sessions. */
final class SignOutOwnerEverywhere implements RowActionInterface
{
    public function __construct(
        private readonly SignInStoreInterface $signIns,
        private readonly UserProviderInterface $users,
        private readonly SignOutEverywhere $signOut,
    ) {}

    public function run(string $id): string
    {
        $signIn = $this->signIns->find($id);
        $owner = $signIn === null ? null : $this->users->byIdentifier($signIn->userId);

        return ($this->signOut)($owner ?? throw WriteRejected::on('id', 'That sign-in has already ended.'));
    }
}
