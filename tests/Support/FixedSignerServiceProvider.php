<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Security\Signer;

/**
 * Binds a {@see Signer} under a fixed key, so the integration tests get the
 * working signer the CSRF guard needs while still booting from the bare,
 * .env-less Environment they deliberately use. The production provider reads
 * APP_KEY via Environment::required() and is covered by
 * {@see \Hydra\Core\Tests\Unit\SignerServiceProviderTest}.
 */
final class FixedSignerServiceProvider extends ServiceProvider
{
    /** Any 64-hex (32-byte) key; the value is irrelevant to what these tests assert. */
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    public function register(ContainerInterface $container): void
    {
        $container->instance(Signer::class, Signer::fromHex(self::KEY_HEX));
    }
}
