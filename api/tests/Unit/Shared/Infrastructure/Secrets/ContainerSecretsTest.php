<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Secrets;

use App\Shared\Infrastructure\Secrets\ContainerSecrets;
use PHPUnit\Framework\TestCase;

/**
 * A deployment hands each secret to its container as a compose secret, a file under /run/secrets, never as its
 * environment (docs/SPEC.md § 7, Docker secrets): `docker inspect` and `docker compose config` then show none. The
 * application still reads its environment, so every PHP process, the server's workers and a `docker compose exec`'d
 * console alike, reads the files into it before anything else runs.
 */
final class ContainerSecretsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/container-secrets-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testEachFileBecomesTheVariableItNamesInCapitals(): void
    {
        $this->secret('app_secret', 'a-secret-of-its-own');
        $this->secret('mailer_dsn', 'smtp://shop%40gmail.invalid:app@smtp.gmail.invalid:587');

        self::assertSame(
            ['APP_SECRET' => 'a-secret-of-its-own', 'MAILER_DSN' => 'smtp://shop%40gmail.invalid:app@smtp.gmail.invalid:587'],
            ContainerSecrets::values($this->directory, []),
        );
    }

    /** Development and the tests mount none: nothing is read and nothing changes. */
    public function testNoDirectoryReadsNothing(): void
    {
        self::assertSame([], ContainerSecrets::values($this->directory.'/absent', ['DATABASE_URL' => 'postgresql://twes_app:twes-app@postgres/twes']));
    }

    /** A template with no secrets mounted would leave api/.env's development address in place: refused. */
    public function testATemplateWithNothingMountedIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POSTGRES_APP_PASSWORD');

        ContainerSecrets::values($this->directory.'/absent', ['DATABASE_URL_TEMPLATE' => 'postgresql://twes_app:{postgres_app_password}@postgres/twes']);
    }

    /** What compose wrote is the value, byte for byte: nothing trimmed, since a passphrase is what it is. */
    public function testTheValueIsTheFileAsItIs(): void
    {
        $this->secret('restic_password', ' spaced ');

        self::assertSame(['RESTIC_PASSWORD' => ' spaced '], ContainerSecrets::values($this->directory, []));
    }

    public function testAnEmptyFileIsRefusedByItsName(): void
    {
        $this->secret('app_mfa_key', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_MFA_KEY');

        ContainerSecrets::values($this->directory, []);
    }

    /** A program that reads the file itself, as PostgreSQL's image does with POSTGRES_PASSWORD_FILE, is left to read it. */
    public function testAFileTheEnvironmentPointsAtIsLeftToItsReader(): void
    {
        $this->secret('postgres_password', 'owner-password');

        self::assertSame([], ContainerSecrets::values($this->directory, ['POSTGRES_PASSWORD_FILE' => $this->directory.'/postgres_password']));
    }

    /**
     * The database's passwords sit inside its address, which compose writes as a template with a placeholder in their
     * place, under a name of its own: the address itself must not be in the container's environment (below).
     */
    public function testATemplateMakesItsAddressWithItsSecrets(): void
    {
        $this->secret('postgres_app_password', 'Abc123');
        $this->secret('postgres_password', 'Owner456');

        self::assertSame([
            'POSTGRES_APP_PASSWORD' => 'Abc123',
            'POSTGRES_PASSWORD' => 'Owner456',
            'DATABASE_URL' => 'postgresql://twes_app:Abc123@postgres:5432/twes?serverVersion=18',
            'DATABASE_MIGRATION_URL' => 'postgresql://twes:Owner456@postgres:5432/twes?serverVersion=18',
        ], ContainerSecrets::values($this->directory, [
            'DATABASE_URL_TEMPLATE' => 'postgresql://twes_app:{postgres_app_password}@postgres:5432/twes?serverVersion=18',
            'DATABASE_MIGRATION_URL_TEMPLATE' => 'postgresql://twes:{postgres_password}@postgres:5432/twes?serverVersion=18',
        ]));
    }

    public function testAPlaceholderWithNoSecretIsRefusedByItsName(): void
    {
        $this->secret('app_secret', 'a-secret-of-its-own');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POSTGRES_APP_PASSWORD');

        ContainerSecrets::values($this->directory, ['DATABASE_URL_TEMPLATE' => 'postgresql://twes_app:{postgres_app_password}@postgres/twes']);
    }

    /** A password set in an address as it is must not change the address: letters and digits only, as new-instance.sh makes them. */
    public function testAPasswordThatWouldChangeTheAddressIsRefusedWithoutItsValue(): void
    {
        $this->secret('postgres_app_password', 'p@ss/word');

        try {
            ContainerSecrets::values($this->directory, ['DATABASE_URL_TEMPLATE' => 'postgresql://twes_app:{postgres_app_password}@postgres/twes']);
            self::fail('a password holding @ and / was set in the address');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('POSTGRES_APP_PASSWORD', $refused->getMessage());
            self::assertStringNotContainsString('p@ss/word', $refused->getMessage());
        }
    }

    /**
     * FrankenPHP's worker runner hands each request the values of boot UNDER the request's own environment
     * (`$_SERVER += $server`), so a variable the container's environment also holds, even empty, beats the secret in
     * every request while a console reads the secret: refused at start, naming it.
     */
    public function testASecretTheEnvironmentAlsoHoldsIsRefused(): void
    {
        $this->secret('mailer_dsn', 'smtp://relay.invalid:587');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MAILER_DSN is a mounted secret and in the container\'s environment');

        ContainerSecrets::values($this->directory, ['MAILER_DSN' => '']);
    }

    /** The same for an address: its placeholder would reach the workers as it is. */
    public function testAnAddressWithAPlaceholderInTheEnvironmentIsRefused(): void
    {
        $this->secret('postgres_app_password', 'Abc123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DATABASE_URL_TEMPLATE');

        ContainerSecrets::values($this->directory, ['DATABASE_URL' => 'postgresql://twes_app:{postgres_app_password}@postgres/twes']);
    }

    public function testAnAddressBesideItsTemplateIsRefused(): void
    {
        $this->secret('postgres_app_password', 'Abc123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DATABASE_URL is in the container\'s environment beside DATABASE_URL_TEMPLATE');

        ContainerSecrets::values($this->directory, [
            'DATABASE_URL' => 'postgresql://twes_app:twes-app@postgres/twes',
            'DATABASE_URL_TEMPLATE' => 'postgresql://twes_app:{postgres_app_password}@postgres/twes',
        ]);
    }

    public function testANameNoVariableCanCarryIsRefused(): void
    {
        $this->secret('Bad-Name', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad-Name');

        ContainerSecrets::values($this->directory, []);
    }

    /** What the kernel reads: $_SERVER and $_ENV, and getenv() for whatever asks the process. */
    public function testLoadingWritesEveryPlaceTheApplicationReads(): void
    {
        $this->secret('twes_secret_probe', 'probe-value');
        try {
            ContainerSecrets::load($this->directory);

            self::assertSame('probe-value', $_SERVER['TWES_SECRET_PROBE'] ?? null);
            self::assertSame('probe-value', $_ENV['TWES_SECRET_PROBE'] ?? null);
            self::assertSame('probe-value', getenv('TWES_SECRET_PROBE'));
        } finally {
            unset($_SERVER['TWES_SECRET_PROBE'], $_ENV['TWES_SECRET_PROBE']);
            putenv('TWES_SECRET_PROBE');
        }
    }

    /** What is checked at start is the process's own environment, which the workers' requests are built from. */
    public function testLoadingRefusesASecretTheProcessEnvironmentHolds(): void
    {
        $this->secret('twes_secret_probe', 'probe-value');
        putenv('TWES_SECRET_PROBE=');
        try {
            ContainerSecrets::load($this->directory);
            self::fail('a secret the process environment also holds was loaded');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('TWES_SECRET_PROBE', $refused->getMessage());
            self::assertArrayNotHasKey('TWES_SECRET_PROBE', $_SERVER);
        } finally {
            putenv('TWES_SECRET_PROBE');
        }
    }

    /** Composer loads it with every autoloader, so a `docker compose exec`'d console reads the files as the server does. */
    public function testComposerLoadsItWithTheAutoloader(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../../../composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['autoload'] ?? null);
        self::assertIsArray($composer['autoload']['files'] ?? null);
        self::assertContains('config/container-secrets.php', $composer['autoload']['files']);
        self::assertStringContainsString('ContainerSecrets::load(', (string) file_get_contents(__DIR__.'/../../../../../config/container-secrets.php'));
    }

    private function secret(string $name, string $value): void
    {
        file_put_contents($this->directory.'/'.$name, $value);
    }
}
