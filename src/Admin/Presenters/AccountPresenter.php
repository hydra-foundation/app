<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Entities\User;
use App\ViewModels\ChangeEmailViewModel;
use App\ViewModels\ChangePasswordViewModel;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;

final class AccountPresenter implements PresenterInterface
{
    public function __construct(private readonly GuardInterface $guard) {}

    /**
     * @param array<string, string> $errors
     * @param string $form which of the two forms the errors belong to
     */
    public function present(array $errors = [], string $form = 'password'): array
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        return [
            'user' => $user,
            'vm' => new ChangePasswordViewModel($form === 'password' ? $errors : []),
            'emailVm' => new ChangeEmailViewModel($form === 'email' ? $errors : []),
        ];
    }
}
