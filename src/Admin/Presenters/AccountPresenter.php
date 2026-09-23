<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Entities\User;
use App\ViewModels\ChangePasswordViewModel;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;

final class AccountPresenter implements PresenterInterface
{
    public function __construct(private readonly GuardInterface $guard) {}

    /** @param array<string, string> $errors */
    public function present(array $errors = []): array
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        return ['user' => $user, 'vm' => new ChangePasswordViewModel($errors)];
    }
}
