<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Admin\Sources\AccessTokenSource;
use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;

/**
 * Every token of the owner of this one, for a lost laptop or an account
 * nobody trusts any more. Through the store's revokeAll(), like a single
 * revoke goes through its revoke().
 */
final class RevokeOwnerTokens implements RowActionInterface
{
    public function __construct(
        private readonly AccessTokenSource $source,
        private readonly ApiTokenStoreInterface $tokens,
    ) {}

    public function run(string $id): string
    {
        $owner = $this->source->owner($id);
        $name = (string) ($this->source->find($id)['owner'] ?? $owner->getAuthIdentifier());

        return match ($revoked = $this->tokens->revokeAll($owner)) {
            1 => "{$name}'s one API token revoked.",
            default => "All {$revoked} of {$name}'s API tokens revoked.",
        };
    }
}
