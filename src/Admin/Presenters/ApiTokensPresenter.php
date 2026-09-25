<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Entities\User;
use App\ViewModels\ApiTokensViewModel;
use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;

final class ApiTokensPresenter implements PresenterInterface
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly ApiTokenStoreInterface $tokens,
        private readonly TimezoneInterface $timezone,
    ) {}

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     * @param string|null $plain a token just made, shown once
     */
    public function present(array $errors = [], array $old = [], ?string $plain = null, ?string $plainName = null): array
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        $zone = $this->timezone->zone();

        return [
            'user' => $user,
            'vm' => new ApiTokensViewModel(
                tokens: array_map(fn (ApiToken $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'created' => $this->local($t->createdAt, $zone),
                    'used' => $t->lastUsedAt === null ? null : $this->local($t->lastUsedAt, $zone),
                    'expires' => $t->expiresAt === null ? null : $this->local($t->expiresAt, $zone),
                ], $this->tokens->forUser($user)),
                plain: $plain,
                plainName: $plainName,
                errors: $errors,
                old: $old,
            ),
        ];
    }

    private function local(DateTimeImmutable $at, DateTimeZone $zone): string
    {
        return $at->setTimezone($zone)->format('Y-m-d H:i');
    }
}
