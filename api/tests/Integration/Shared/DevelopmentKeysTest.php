<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/SPEC.md § 8 row 22, review S10: the keys in `api/.env` are committed, so they are public, and they are
 * working defaults rather than placeholders — `compose.yaml` hands the very same literals to the containers.
 * They are right for development and must never carry a production boot: whoever reads the repository could
 * forge a realtime token, drive Centrifugo's HTTP API, and decrypt every TOTP secret the database holds.
 *
 * A key is not something a deployment can be trusted to notice it forgot, so production refuses to start.
 */
final class DevelopmentKeysTest extends KernelTestCase
{
    private const string COMMITTED_MFA_KEY = 'ojCIqjd0KzQ4JrX9wyEpy58LdtCAQ56MTwM8CWA0XOc=';
    private const string COMMITTED_TOKEN_KEY = 'twes-in-development-realtime-token-key-0001';
    private const string COMMITTED_API_KEY = 'twes-in-development-realtime-api-key';

    private const array NAMES = ['APP_SECRET', 'APP_MFA_KEY', 'REALTIME_TOKEN_KEY', 'REALTIME_API_KEY'];

    /** @var array<string, string|false> what the environment held before a case changed it */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::NAMES as $name) {
            $value = $_SERVER[$name] ?? false;
            $this->saved[$name] = \is_string($value) ? $value : false;
        }
    }

    protected function tearDown(): void
    {
        $this->discardKernel();
        foreach ($this->saved as $name => $value) {
            if (false === $value) {
                unset($_SERVER[$name], $_ENV[$name]);

                continue;
            }
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
        parent::tearDown();
    }

    /**
     * The control, and it is not a formality: if production could not boot here for some unrelated reason — an
     * empty secret, an unreachable database — every refusal below would pass while proving nothing at all.
     */
    public function testProductionBootsOnKeysOfItsOwn(): void
    {
        $this->environment();

        $kernel = self::bootKernel(['environment' => 'prod', 'debug' => false]);

        self::assertSame('prod', $kernel->getEnvironment());
    }

    public function testProductionRefusesTheCommittedMfaKey(): void
    {
        $this->environment(mfaKey: self::COMMITTED_MFA_KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_MFA_KEY/');

        self::bootKernel(['environment' => 'prod', 'debug' => false]);
    }

    public function testProductionRefusesTheCommittedRealtimeTokenKey(): void
    {
        $this->environment(tokenKey: self::COMMITTED_TOKEN_KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/REALTIME_TOKEN_KEY/');

        self::bootKernel(['environment' => 'prod', 'debug' => false]);
    }

    public function testProductionRefusesTheCommittedRealtimeApiKey(): void
    {
        $this->environment(apiKey: self::COMMITTED_API_KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/REALTIME_API_KEY/');

        self::bootKernel(['environment' => 'prod', 'debug' => false]);
    }

    /** An empty secret is the same hole wearing a different hat: `api/.env` ships `APP_SECRET=`. */
    public function testProductionRefusesAnEmptySecret(): void
    {
        $this->environment(secret: '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET/');

        self::bootKernel(['environment' => 'prod', 'debug' => false]);
    }

    /**
     * And the other way round, which is the half that keeps the guard honest: development and the test suite run
     * on these very literals, so a guard that refused them everywhere would stop the stack rather than protect it.
     */
    public function testTheTestEnvironmentStillBootsOnThem(): void
    {
        $this->environment(mfaKey: self::COMMITTED_MFA_KEY, tokenKey: self::COMMITTED_TOKEN_KEY, apiKey: self::COMMITTED_API_KEY);

        $kernel = self::bootKernel(['environment' => 'test']);

        self::assertSame('test', $kernel->getEnvironment());
    }

    /**
     * Dropped rather than shut down, which is not fussiness: `KernelTestCase::ensureKernelShutdown()` BOOTS the
     * kernel to read its container for the cache directories, so a kernel a refusal left assigned but unbooted
     * would be booted a second time by the teardown — and refused there, turning a passing case into an error in
     * a method that never asked for a kernel at all.
     */
    private function discardKernel(): void
    {
        static::$kernel = null;
        static::$booted = false;
    }

    /** Sets the four, each defaulting to a value no repository carries. */
    private function environment(?string $secret = null, ?string $mfaKey = null, ?string $tokenKey = null, ?string $apiKey = null): void
    {
        $this->discardKernel();
        $values = [
            'APP_SECRET' => $secret ?? bin2hex(random_bytes(16)),
            'APP_MFA_KEY' => $mfaKey ?? base64_encode(random_bytes(32)),
            'REALTIME_TOKEN_KEY' => $tokenKey ?? bin2hex(random_bytes(32)),
            'REALTIME_API_KEY' => $apiKey ?? bin2hex(random_bytes(16)),
        ];
        foreach ($values as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
    }
}
