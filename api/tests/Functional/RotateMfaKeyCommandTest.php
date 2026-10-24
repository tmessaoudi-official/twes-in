<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rotating APP_MFA_KEY re-seals every stored authenticator secret under the new key, so nobody has to enrol again.
 * The previous key comes on standard input, never on the command line; every value is opened before any is written,
 * and one that no key opens refuses the whole run, leaving the table as it was.
 */
final class RotateMfaKeyCommandTest extends ApiTestCase
{
    private const string PREVIOUS = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';
    private const string ANOTHER = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';
    private const string PASSWORD = 'correct horse battery staple';

    public function testEverySecretSealedUnderThePreviousKeyIsResealedAndItsOwnerStillSignsInWithACode(): void
    {
        $this->createUser('enrolled@twes.local', self::PASSWORD, authenticator: true);
        $this->createUser('pending@twes.local', self::PASSWORD);
        $this->createUser('none@twes.local', self::PASSWORD);
        $this->sealUnder(self::PREVIOUS, 'enrolled@twes.local', self::AUTHENTICATOR_SECRET);
        $this->sealUnder(self::PREVIOUS, 'pending@twes.local', 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU');

        $tester = $this->rotate(self::PREVIOUS."\n");

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Resealed 2 authenticator secrets', $tester->getDisplay());
        $current = static::getContainer()->get(SodiumSecretCipher::class);
        self::assertSame(self::AUTHENTICATOR_SECRET, $current->decrypt($this->stored('enrolled@twes.local')));
        self::assertSame('KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU', $current->decrypt($this->stored('pending@twes.local')));
        self::assertNull($this->storedOrNull('none@twes.local'));

        // login() answers the code step with a code from the secret, so the account is in only if the API opened it.
        $this->login('enrolled@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testANewAppsSecretWaitingBesideTheOneInForceIsResealedToo(): void
    {
        // A replacement begun before the rotation is confirmed after it: its waiting secret moves to the new key as well.
        $this->createUser('moving@twes.local', self::PASSWORD, authenticator: true);
        $this->sealUnder(self::PREVIOUS, 'moving@twes.local', self::AUTHENTICATOR_SECRET);
        $this->em()->getConnection()->executeStatement(
            'UPDATE "user" SET totp_replacement_secret = ? WHERE email = ?',
            [(new SodiumSecretCipher(self::PREVIOUS))->encrypt('KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU'), 'moving@twes.local'],
        );

        $tester = $this->rotate(self::PREVIOUS."\n");

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Resealed 2 authenticator secrets', $tester->getDisplay());
        $waiting = $this->em()->getConnection()->fetchOne('SELECT totp_replacement_secret FROM "user" WHERE email = ?', ['moving@twes.local']);
        self::assertIsString($waiting);
        self::assertSame('KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU', static::getContainer()->get(SodiumSecretCipher::class)->decrypt($waiting));
    }

    public function testASecretNoKeyOpensRefusesTheRunAndChangesNothing(): void
    {
        $this->createUser('first@twes.local', self::PASSWORD, authenticator: true);
        $this->createUser('lost@twes.local', self::PASSWORD, authenticator: true);
        $this->sealUnder(self::PREVIOUS, 'first@twes.local', self::AUTHENTICATOR_SECRET);
        $this->sealUnder(self::ANOTHER, 'lost@twes.local', self::AUTHENTICATOR_SECRET);
        $before = $this->stored('first@twes.local');

        $tester = $this->rotate(self::PREVIOUS."\n");

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('1 authenticator secret opens with neither key', $tester->getDisplay());
        self::assertSame($before, $this->stored('first@twes.local'));
    }

    public function testASecondRunFindsEverySecretAlreadyUnderTheCurrentKey(): void
    {
        $this->createUser('enrolled@twes.local', self::PASSWORD, authenticator: true);
        $this->sealUnder(self::PREVIOUS, 'enrolled@twes.local', self::AUTHENTICATOR_SECRET);
        self::assertSame(0, $this->rotate(self::PREVIOUS."\n")->getStatusCode());
        $after = $this->stored('enrolled@twes.local');

        $again = $this->rotate(self::PREVIOUS."\n");

        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        self::assertStringContainsString('Nothing to reseal', $again->getDisplay());
        self::assertSame($after, $this->stored('enrolled@twes.local'));
    }

    public function testAPreviousKeyThatIsNotOneIsRefusedBeforeAnythingIsRead(): void
    {
        $this->createUser('enrolled@twes.local', self::PASSWORD, authenticator: true);

        foreach (["\n", "not-a-key\n", ''] as $stdin) {
            $tester = $this->rotate($stdin);
            self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('previous APP_MFA_KEY', $tester->getDisplay());
        }
    }

    private function sealUnder(string $key, string $email, string $secret): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE "user" SET totp_secret = ? WHERE email = ?',
            [(new SodiumSecretCipher($key))->encrypt($secret), $email],
        );
        // The users created above are still managed with the value they were created with.
        $this->em()->clear();
    }

    private function stored(string $email): string
    {
        return $this->storedOrNull($email) ?? throw new \LogicException("$email holds no secret");
    }

    private function storedOrNull(string $email): ?string
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT totp_secret FROM "user" WHERE email = ?', [$email]);

        return \is_string($value) ? $value : null;
    }

    private function rotate(string $stdin): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:keys:rotate-mfa'));
        $tester->setInputs('' === $stdin ? [] : [rtrim($stdin, "\n")]);
        $tester->execute([], ['interactive' => false]);

        return $tester;
    }
}
