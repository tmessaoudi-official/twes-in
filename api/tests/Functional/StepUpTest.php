<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up: a signed-in person proves who they are again, with their password or a passkey, before something a stranger
 * at the same screen must not do — leaving customer view first. The passkey half runs through the real WebAuthn
 * validators, with a PHP authenticator.
 */
final class StepUpTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string STEP_UP = '/api/auth/step-up';
    private const string PASSKEY = '/api/auth/step-up/passkey';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testThePasswordConfirmsAndAnotherOneIsRefusedAndAudited(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->postJson(self::STEP_UP, ['password' => 'not-the-password']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_credentials', $this->stringAt($this->json(), 'error'));

        $this->postJson(self::STEP_UP, []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame([1, 2], [$this->audited('auth.step_up'), $this->audited('auth.step_up_refused')], 'one confirmed; the wrong password and the empty body are both refusals');
    }

    public function testGuessingThePasswordSharesTheBudgetOfFiveAttempts(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            $this->postJson(self::STEP_UP, ['password' => 'guess-'.$i]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the right password is not a way round the budget');
    }

    /**
     * Audit 2026-10-06, D-4: a wrong password here is a guess at the same account as a wrong one at the sign-in, so it
     * counts toward the same lockout; otherwise an open session is a way to guess the password all day at the limiter's pace.
     */
    public function testWrongPasswordsHereLockTheAccountAsWrongSignInsDo(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        for ($i = 0; $i < 4; ++$i) {
            $this->postJson(self::STEP_UP, ['password' => 'guess-'.$i]);
        }
        self::assertFalse($this->account('someone@twes.local')->isLockedAt(new \DateTimeImmutable()), 'four are not enough');
        $this->postJson(self::STEP_UP, ['password' => 'guess-4']);

        self::assertTrue($this->account('someone@twes.local')->isLockedAt(new \DateTimeImmutable()));
        $this->client->getCookieJar()->clear();
        $this->postJson('/api/auth/login', ['email' => 'someone@twes.local', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the right password no longer signs in while it is locked');
    }

    private function account(string $email): User
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString($email)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testItNeedsASessionAndTheCsrfHeader(): void
    {
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->postJson(self::PASSKEY.'/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        foreach ([self::STEP_UP, self::PASSKEY.'/options', self::PASSKEY] as $path) {
            $this->postJson($path, [], withCsrf: false);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path);
        }
    }

    public function testAPasskeyConfirmsAndAStaleOrForeignAssertionDoesNot(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $creation = $this->json();
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($creation)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $request = $this->passkeyOptions();
        self::assertSame([$authenticator->id()], array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['id'] ?? null : null, $this->arrayAt($request, 'allowCredentials')));
        $this->postJson(self::PASSKEY, ['credential' => $authenticator->assert($request, challenge: FakeAuthenticator::base64Url(random_bytes(32)))]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a signature over another challenge');

        $this->postJson(self::PASSKEY, ['credential' => $authenticator->assert($request)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the challenge was spent by the failed attempt');

        $this->postJson(self::PASSKEY, ['credential' => (new FakeAuthenticator())->assert($this->passkeyOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a passkey of nobody here');

        $this->postJson(self::PASSKEY, ['credential' => $authenticator->assert($this->passkeyOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(1, $this->audited('auth.step_up'));
    }

    public function testAnAccountWithoutAPasskeyIsToldSoWhenItAsksForOptions(): void
    {
        $this->createUser('plain@twes.local', self::PASSWORD);
        $this->login('plain@twes.local', self::PASSWORD);

        $this->postJson(self::PASSKEY.'/options', null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));
    }

    /** @return array<string, mixed> */
    private function passkeyOptions(): array
    {
        $this->postJson(self::PASSKEY.'/options', null);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function audited(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
