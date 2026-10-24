<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Mfa\ReplaceSecondFactors;
use App\Identity\Domain\Model\Passkey;
use App\Identity\Domain\Model\RecoveryCodeEntry;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Domain\ValueObject\RecoveryCode;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A required account (every operator) that lost its phone and spent its recovery codes has no way back on screen: the
 * operator's own reset was refused, so whoever runs the installation replaces its factors from the server. The account
 * then signs in with its password and is held at the enrolment until it sets a new one up.
 */
final class ReplaceSecondFactorsCommandTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testTheAuthenticatorAndRecoveryCodesGoEverySessionEndsAndTheNextSignInEnrolsAgain(): void
    {
        $this->createUser('lost@twes.local', self::PASSWORD, operator: true);
        $this->withRecoveryCodes('lost@twes.local', 3);
        $this->withPasskey('lost@twes.local');
        $this->login('lost@twes.local', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $tester = $this->replace(['email' => 'lost@twes.local', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('authenticator app and 3 recovery codes', $tester->getDisplay());
        self::assertSame(['factors_replaced'], $this->securityNotices(), 'its owner is told by mail');
        $user = $this->userOf('lost@twes.local');
        self::assertFalse($user->hasTotp());
        self::assertSame(0, $this->rowsFor('recovery_code', $user));
        self::assertSame(1, $this->rowsFor('passkey', $user), 'a passkey stays unless it is asked to go');

        // The session opened before the replacement is over.
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $this->postJson('/api/auth/login', ['email' => 'lost@twes.local', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        $actions = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action, changes, actor_user_id FROM audit_log WHERE entity_type = ? AND entity_id = ?',
            ['user', $user->getId()->toRfc4122()],
        );
        $replaced = array_values(array_filter($actions, static fn (array $row): bool => ReplaceSecondFactors::REPLACED === $row['action']));
        self::assertCount(1, $replaced);
        self::assertNull($replaced[0]['actor_user_id'], 'no account did it: the installation did');
        $changes = $replaced[0]['changes'];
        self::assertIsString($changes);
        self::assertSame(['factors' => ['authenticator', 'recovery_codes']], json_decode($changes, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheAccountIsHeldAtTheEnrolmentAfterSigningInWithItsPasswordAlone(): void
    {
        $this->createUser('lost@twes.local', self::PASSWORD, operator: true);
        self::assertSame(Command::SUCCESS, $this->replace(['email' => 'lost@twes.local', '--force' => true])->getStatusCode());

        $this->postJson('/api/auth/login', ['email' => 'lost@twes.local', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertNotTrue($this->json()['mfaRequired'] ?? null, 'there is no factor left to ask for');
        $this->client->request('GET', '/api/platform/companies');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('mfa_enrolment_required', $this->json()['error'] ?? null);
    }

    public function testPasskeysGoTooWhenAsked(): void
    {
        $this->createUser('lost@twes.local', self::PASSWORD, operator: true);
        $this->withPasskey('lost@twes.local');
        $this->withPasskey('lost@twes.local');

        $tester = $this->replace(['email' => 'lost@twes.local', '--force' => true, '--with-passkeys' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('2 passkeys', $tester->getDisplay());
        self::assertSame(0, $this->rowsFor('passkey', $this->userOf('lost@twes.local')));
    }

    public function testAnAddressNoAccountHoldsChangesNothing(): void
    {
        $this->createUser('kept@twes.local', self::PASSWORD, operator: true);

        $tester = $this->replace(['email' => 'nobody@twes.local', '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No account has the address nobody@twes.local', $tester->getDisplay());
        self::assertTrue($this->userOf('kept@twes.local')->hasTotp());
    }

    public function testWithoutForceAScriptIsRefusedAndAPersonIsAskedFirst(): void
    {
        $this->createUser('lost@twes.local', self::PASSWORD, operator: true);

        $script = $this->replace(['email' => 'lost@twes.local']);
        self::assertSame(Command::INVALID, $script->getStatusCode());
        self::assertStringContainsString('--force', $script->getDisplay());
        self::assertTrue($this->userOf('lost@twes.local')->hasTotp());

        $declined = $this->replace(['email' => 'lost@twes.local'], interactive: true, answers: ['no']);
        self::assertSame(Command::FAILURE, $declined->getStatusCode());
        self::assertTrue($this->userOf('lost@twes.local')->hasTotp());

        $confirmed = $this->replace(['email' => 'lost@twes.local'], interactive: true, answers: ['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->getStatusCode(), $confirmed->getDisplay());
        self::assertFalse($this->userOf('lost@twes.local')->hasTotp());
    }

    public function testAnAccountWithNothingToReplaceIsSaidSoAndLeftAsItIs(): void
    {
        $this->createUser('plain@twes.local', self::PASSWORD);

        $tester = $this->replace(['email' => 'plain@twes.local', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('has no second factor to replace', $tester->getDisplay());
        $audited = $this->em()->getConnection()->fetchOne('SELECT count(*) FROM audit_log WHERE action = ?', [ReplaceSecondFactors::REPLACED]);
        self::assertSame(0, $audited);
    }

    private function withRecoveryCodes(string $email, int $count): void
    {
        $user = $this->userOf($email);
        foreach (\array_slice(RecoveryCode::generateSet(), 0, $count) as $code) {
            $this->em()->persist(new RecoveryCodeEntry($user, $code->hash()));
        }
        $this->em()->flush();
    }

    private function withPasskey(string $email): void
    {
        $this->em()->persist(new Passkey($this->userOf($email), bin2hex(random_bytes(16)), '{}', 'Téléphone'));
        $this->em()->flush();
    }

    private function userOf(string $email): User
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString($email)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function rowsFor(string $table, User $user): int
    {
        $count = $this->em()->getConnection()->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE user_id = ?', $table), [$user->getId()->toRfc4122()]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param array<string, string|bool> $arguments
     * @param list<string>               $answers
     */
    private function replace(array $arguments, bool $interactive = false, array $answers = []): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:mfa:replace'));
        $tester->setInputs($answers);
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }
}
