<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys as a second factor beside the authenticator app (G1c): registered from a signed-in session, then asked for
 * after the password exactly where a code would be. The credentials come from a PHP authenticator, so every check below
 * runs through the real WebAuthn validators: challenge, origin, relying party, user verification, signature, counter.
 */
final class PasskeyTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testAFirstPasskeyIssuesRecoveryCodesAndThenFinishesEveryLogin(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $authenticator = new FakeAuthenticator();

        $options = $this->creationOptions();
        self::assertSame('localhost', $this->stringAt($this->section($options, 'rp'), 'id'));
        self::assertSame('required', $this->stringAt($this->section($options, 'authenticatorSelection'), 'userVerification'));
        self::assertSame([], $options['excludeCredentials'] ?? []);

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertCount(10, $this->arrayAt($body, 'recoveryCodes'));
        self::assertSame('Laptop', $this->stringAt($this->section($body, 'passkey'), 'name'));
        self::assertSame(1, $this->audited('auth.passkey_registered'));

        $this->getJson('/api/auth/me');
        $mfa = $this->section($this->json(), 'mfa');
        self::assertTrue($this->boolAt($mfa, 'enrolled'));
        self::assertFalse($this->boolAt($mfa, 'totp'));
        self::assertSame(1, $mfa['passkeys'] ?? null);

        // From now on the password alone opens nothing.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $request = $this->requestOptions();
        self::assertSame('required', $this->stringAt($request, 'userVerification'));
        self::assertSame([$authenticator->id()], array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['id'] ?? null : null, $this->arrayAt($request, 'allowCredentials')));

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($request)]);

        self::assertResponseIsSuccessful();
        self::assertSame('someone@twes.local', $this->stringAt($this->section($this->json(), 'user'), 'email'));
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testARecoveryCodeFinishesTheLoginOfAnAccountWhoseOnlyFactorIsAPasskey(): void
    {
        $codes = $this->withPasskey('someone@twes.local', new FakeAuthenticator());
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]]);

        self::assertResponseIsSuccessful();
    }

    public function testAnotherPasskeyIssuesNoNewCodesAndCannotRegisterTheSameCredentialTwice(): void
    {
        $first = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $first);

        $options = $this->creationOptions();
        self::assertSame([$first->id()], array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['id'] ?? null : null, $this->arrayAt($options, 'excludeCredentials')));
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Phone', 'credential' => (new FakeAuthenticator())->register($options)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([], $this->arrayAt($this->json(), 'recoveryCodes'));

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Again', 'credential' => $first->register($this->creationOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));

        $this->getJson('/api/auth/mfa/passkeys');
        self::assertSame(['Laptop', 'Phone'], array_map(static fn (mixed $p): mixed => \is_array($p) ? $p['name'] ?? null : null, $this->arrayAt($this->json(), 'passkeys')));
    }

    public function testARegistrationChallengeIsSpentByTheFirstAttemptEvenAFailedOne(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $authenticator = new FakeAuthenticator();
        $options = $this->creationOptions();

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options, challenge: FakeAuthenticator::base64Url(random_bytes(32)))]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));
    }

    public function testARegistrationFromAnotherOriginOrWithoutUserVerificationIsRefused(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $authenticator = new FakeAuthenticator();

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->creationOptions(), origin: 'https://evil.example')]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->creationOptions(), flags: FakeAuthenticator::USER_PRESENT)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->getJson('/api/auth/mfa/passkeys');
        self::assertSame([], $this->arrayAt($this->json(), 'passkeys'));
    }

    public function testALoginChallengeIsSpentByTheFirstAttempt(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $authenticator);
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $request = $this->requestOptions();

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($request, challenge: FakeAuthenticator::base64Url(random_bytes(32)))]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($request)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));
    }

    public function testAPasskeyOfAnotherAccountIsRefusedExactlyLikeAnUnknownOne(): void
    {
        $theirs = new FakeAuthenticator();
        $this->withPasskey('other@twes.local', $theirs);
        $this->signOut();
        $this->withPasskey('someone@twes.local', new FakeAuthenticator());
        $this->signOut();

        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $theirs->assert($this->requestOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $foreign = $this->json();

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => (new FakeAuthenticator())->assert($this->requestOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($this->json(), $foreign);
    }

    public function testASignatureCounterThatDoesNotMoveForwardIsRefused(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $authenticator, signCount: 5);

        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($this->requestOptions(), signCount: 6)]);
        self::assertResponseIsSuccessful();

        // A clone of the key would replay the counter it was copied at.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($this->requestOptions(), signCount: 6)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPasskeyLoginNeedsThePasswordStepFirst(): void
    {
        $this->postJson('/api/auth/mfa/passkey-login/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('mfa_not_pending', $this->stringAt($this->json(), 'error'));

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('mfa_not_pending', $this->stringAt($this->json(), 'error'));
    }

    public function testAPasskeyLoginSharesTheBudgetOfFiveAttempts(): void
    {
        $this->withPasskey('someone@twes.local', new FakeAuthenticator());
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            $this->postJson('/api/auth/mfa/passkey-login', ['credential' => (new FakeAuthenticator())->assert($this->requestOptions())]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => (new FakeAuthenticator())->assert($this->requestOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testRegisteringNeedsASession(): void
    {
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPasskeyIsRemovedOnlyByItsOwnerAndNeverAsTheLastFactorACompanyRequires(): void
    {
        $this->withPasskey('other@twes.local', new FakeAuthenticator());
        [$theirs] = $this->listedIds();
        $this->signOut();

        // Required from the start: registering stays open to an account held back for want of a factor.
        $company = $this->createCompany('Secure');
        $company->requireMfa(true);
        $this->createUser('someone@twes.local', self::PASSWORD, $company);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->getJson('/api/me/companies');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'held back until the account has a factor');
        $this->registerPasskey(new FakeAuthenticator());
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful('a passkey is a factor the requirement accepts');
        $this->registerPasskey(new FakeAuthenticator());
        [$first, $second] = $this->listedIds();

        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$theirs);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$first);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(1, $this->audited('auth.passkey_removed'));

        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$second);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_last_factor', $this->stringAt($this->json(), 'error'));
    }

    public function testEveryPasskeyEndpointThatChangesSomethingWantsTheCsrfHeader(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        foreach (['/api/auth/mfa/passkeys/options', '/api/auth/mfa/passkeys', '/api/auth/mfa/passkey-login/options', '/api/auth/mfa/passkey-login'] as $path) {
            $this->postJson($path, [], withCsrf: false);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path);
            self::assertSame('csrf_token_missing', $this->stringAt($this->json(), 'error'));
        }

        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/0190e6f5-0000-7000-8000-000000000000', withCsrf: false);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Creates the account, signs it in and registers one passkey named Laptop through the real endpoints.
     *
     * @return list<string> the recovery codes the first passkey issued
     */
    private function withPasskey(string $email, FakeAuthenticator $authenticator, int $signCount = 0): array
    {
        $this->createUser($email, self::PASSWORD);
        $this->login($email, self::PASSWORD);

        return $this->registerPasskey($authenticator, $signCount);
    }

    /** @return list<string> */
    private function registerPasskey(FakeAuthenticator $authenticator, int $signCount = 0): array
    {
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->creationOptions(), signCount: $signCount)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return array_values(array_filter($this->arrayAt($this->json(), 'recoveryCodes'), 'is_string'));
    }

    /** @return array<string, mixed> */
    private function creationOptions(): array
    {
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        $this->postJson('/api/auth/mfa/passkey-login/options', null);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return list<string> the ids the account's passkey list answers, in its order */
    private function listedIds(): array
    {
        $this->getJson('/api/auth/mfa/passkeys');
        self::assertResponseIsSuccessful();

        return array_values(array_map(static fn (mixed $p): string => \is_array($p) && \is_string($p['id'] ?? null) ? $p['id'] : '', $this->arrayAt($this->json(), 'passkeys')));
    }

    private function audited(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }
}
