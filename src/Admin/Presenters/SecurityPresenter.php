<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Config\AppConfig;
use App\Entities\User;
use App\Repositories\TwoFactorRepository;
use App\Security\TwoFactorSetup;
use App\ViewModels\SecurityViewModel;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Totp;
use Hydra\Http\Exceptions\NotFoundException;

final class SecurityPresenter implements PresenterInterface
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly TwoFactorRepository $store,
        private readonly TwoFactorSetup $setup,
        private readonly Totp $totp,
        private readonly AppConfig $app,
    ) {}

    /**
     * @param array<string, string> $errors
     * @param string $form which form the errors belong to
     * @param list<string>|null $codes recovery codes to show, once
     */
    public function present(array $errors = [], string $form = '', ?array $codes = null): array
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        $enabledAt = $this->store->enabledAt($user);
        $secret = $enabledAt === null ? $this->setup->secret() : null;

        return [
            'user' => $user,
            'vm' => new SecurityViewModel(
                enabled: $enabledAt !== null,
                enabledAt: $enabledAt,
                remaining: count($this->store->recoveryHashes($user)),
                setupSecret: $secret,
                setupUri: $secret === null ? null : $this->totp->uri($secret, $user->username, $this->app->name),
                codes: $codes,
                errors: $errors,
                form: $form,
            ),
        ];
    }
}
