<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Security\Signer;

/**
 * Test counterpart to {@see \Hydra\Core\Security\SignerServiceProvider}: binds a
 * {@see Signer} under a fixed test key, so graph tests get a working signer
 * (the CSRF guard needs one) without depending on an APP_KEY in the environment.
 *
 * The production provider reads APP_KEY via Environment::required(); these
 * integration tests deliberately boot from a bare, .env-less Environment, so
 * they swap in this fixed-key binding exactly as they swap the array session
 * store for the native one. {@see \Hydra\Core\Tests\Unit\SignerServiceProviderTest}
 * covers the real key-reading provider.
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
