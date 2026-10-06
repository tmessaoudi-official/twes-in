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

    public function testGuessingThePasswordSharesTheBudgetOfFiveAttemptsAcrossSessions(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        for ($i = 0; $i < 5; ++$i) {
            $this->postJson(self::STEP_UP, ['password' => 'guess-'.$i]);
        }

        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the right password is not a way round the budget, nor a new sign-in');
    }

    /**
     * Wrong answers here are a session's own (docs/SPEC.md § 7, the C-F3 ruling): a customer typing at the customer screen
     * must not lock the clerk out everywhere, so the fifth ends this session and the account stays open. Guessing all
     * day through an open session is still out of reach: a new session needs the password.
     */
    public function testTheFifthWrongPasswordHereEndsThisSessionAndNeverLocksTheAccount(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        for ($i = 0; $i < 4; ++$i) {
            $this->postJson(self::STEP_UP, ['password' => 'guess-'.$i]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('four are not enough');

        $this->postJson(self::STEP_UP, ['password' => 'guess-4']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('step_up_exhausted', $this->stringAt($this->json(), 'error'));
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'this browser is signed out');

        self::assertFalse($this->account('someone@twes.local')->isLockedAt(new \DateTimeImmutable()), 'the account is not locked');
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful('the clerk signs in again at once');
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
