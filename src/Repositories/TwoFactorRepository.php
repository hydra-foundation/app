<?php

declare(strict_types=1);

namespace App\Repositories;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Core\Security\Encrypter;
use Hydra\Database\Contracts\ConnectionInterface;
use RuntimeException;

/**
 * The second factor over the users table and user_recovery_codes. The secret is
 * encrypted under the row's id, so a ciphertext copied to another row does not
 * decrypt there.
 */
final class TwoFactorRepository implements TwoFactorStoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
    ) {}

    public function secret(AuthenticatableInterface $user): ?string
    {
        $row = $this->db->selectOne('SELECT two_factor_secret FROM users WHERE id = ?', [$this->id($user)]);
        $stored = $row['two_factor_secret'] ?? null;

        if (!is_string($stored)) {
            return null;
        }

        // Null here would read as "no second factor" and let the password alone in.
        return $this->encrypter->decrypt($stored, $this->context($user))
            ?? throw new RuntimeException(
                "The two-factor secret for user {$this->id($user)} does not decrypt: was APP_KEY rotated without APP_PREVIOUS_KEYS?",
            );
    }

    /** When the second factor was turned on, or null while it is off. */
    public function enabledAt(AuthenticatableInterface $user): ?string
    {
        $row = $this->db->selectOne('SELECT two_factor_enabled_at FROM users WHERE id = ?', [$this->id($user)]);
        $at = $row['two_factor_enabled_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    public function claimStep(AuthenticatableInterface $user, int $step): bool
    {
        return $this->db->execute(
            'UPDATE users SET two_factor_step = ?
             WHERE id = ? AND two_factor_secret IS NOT NULL AND (two_factor_step IS NULL OR two_factor_step < ?)',
            [$step, $this->id($user), $step],
        ) > 0;
    }

    public function recoveryHashes(AuthenticatableInterface $user): array
    {
        $rows = $this->db->select('SELECT hash FROM user_recovery_codes WHERE user_id = ?', [$this->id($user)]);

        return array_map(static fn (array $row): string => (string) $row['hash'], $rows);
    }

    public function spendRecoveryHash(AuthenticatableInterface $user, string $hash): bool
    {
        return $this->db->execute(
            'DELETE FROM user_recovery_codes WHERE user_id = ? AND hash = ?',
            [$this->id($user), $hash],
        ) > 0;
    }

    public function enable(AuthenticatableInterface $user, string $secret, array $recoveryHashes): void
    {
        $this->db->transaction(function () use ($user, $secret, $recoveryHashes): void {
            $this->db->execute(
                'UPDATE users SET two_factor_secret = ?, two_factor_enabled_at = CURRENT_TIMESTAMP, two_factor_step = NULL WHERE id = ?',
                [$this->encrypter->encrypt($secret, $this->context($user)), $this->id($user)],
            );
            $this->writeHashes($user, $recoveryHashes);
        });
    }

    public function replaceRecoveryHashes(AuthenticatableInterface $user, array $recoveryHashes): void
    {
        $this->db->transaction(function () use ($user, $recoveryHashes): void {
            $row = $this->db->selectOne('SELECT two_factor_secret FROM users WHERE id = ?', [$this->id($user)]);

            if (($row['two_factor_secret'] ?? null) !== null) {
                $this->writeHashes($user, $recoveryHashes);
            }
        });
    }

    public function disable(AuthenticatableInterface $user): void
    {
        $this->db->transaction(function () use ($user): void {
            $this->db->execute(
                'UPDATE users SET two_factor_secret = NULL, two_factor_enabled_at = NULL, two_factor_step = NULL WHERE id = ?',
                [$this->id($user)],
            );
            $this->db->execute('DELETE FROM user_recovery_codes WHERE user_id = ?', [$this->id($user)]);
        });
    }

    /** @param list<string> $hashes */
    private function writeHashes(AuthenticatableInterface $user, array $hashes): void
    {
        $this->db->execute('DELETE FROM user_recovery_codes WHERE user_id = ?', [$this->id($user)]);

        foreach ($hashes as $hash) {
            $this->db->execute('INSERT INTO user_recovery_codes (user_id, hash) VALUES (?, ?)', [$this->id($user), $hash]);
        }
    }

    private function id(AuthenticatableInterface $user): int
    {
        return (int) $user->getAuthIdentifier();
    }

    private function context(AuthenticatableInterface $user): string
    {
        return 'users.two_factor_secret.' . $this->id($user);
    }
}
