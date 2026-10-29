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
    private const string RECOVERY = '/api/auth/mfa/recovery-codes/passkey';

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
        self::assertSame(['passkey_added'], $this->securityNotices(), 'its owner is told by mail');

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

    /**
     * A passkey that signs in alone must live on the device, with the account it opens: one is asked of every new key,
     * and what the browser says it made is kept, so a key that cannot sign in alone is known.
     */
    public function testANewPasskeyIsAskedToBeDiscoverableAndWhatTheBrowserMadeIsKept(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $options = $this->creationOptions();
        $selection = $this->section($options, 'authenticatorSelection');
        self::assertSame('required', $this->stringAt($selection, 'residentKey'));
        self::assertTrue($this->boolAt($selection, 'requireResidentKey'));
        self::assertTrue($this->boolAt($this->section($options, 'extensions'), 'credProps'));

        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => (new FakeAuthenticator())->register($options, residentKey: true)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Key', 'credential' => (new FakeAuthenticator())->register($this->creationOptions(), residentKey: false)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Silent', 'credential' => (new FakeAuthenticator())->register($this->creationOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson('/api/auth/mfa/passkeys');
        $said = [];
        foreach ($this->arrayAt($this->json(), 'passkeys') as $passkey) {
            self::assertIsArray($passkey);
            self::assertArrayHasKey('discoverable', $passkey);
            self::assertIsString($passkey['name'] ?? null);
            $said[$passkey['name']] = $passkey['discoverable'];
        }
        self::assertSame(['Laptop' => true, 'Key' => false, 'Silent' => null], $said, 'a browser that says nothing leaves it unknown');
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

    /**
     * A lock set while the second factor is owed (wrong codes count toward it) holds for a passkey as for a code: the
     * password was already right, so saying it is locked tells nothing a guesser did not know.
     */
    public function testALockSetWhileTheSecondFactorIsOwedHoldsForAPasskeyToo(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $authenticator);
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
        $request = $this->requestOptions();
        $this->em()->getConnection()->executeStatement(
            'UPDATE "user" SET failed_login_count = 5, locked_until = ? WHERE email = ?',
            [(new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'), 'someone@twes.local'],
        );

        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($request)]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('account_locked', $this->stringAt($this->json(), 'error'));
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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

    public function testAddingOrRemovingAPasskeyWaitsForAFreshProofOfWhoIsAtTheScreen(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));

        $authenticator = new FakeAuthenticator();
        $this->registerPasskey($authenticator);
        $this->registerPasskey(new FakeAuthenticator());
        [, $second] = $this->listedIds();

        // A new sign-in carries no proof of its own: the second factor finishes the login, it is not the step-up.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($this->requestOptions())]);
        self::assertResponseIsSuccessful();
        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$second);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));

        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$second);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
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
        self::assertSame(['passkey_removed'], $this->securityNotices(), 'its owner is told by mail');

        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$second);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_last_factor', $this->stringAt($this->json(), 'error'));
    }

    // While a second factor is required the authenticator is replaced, never removed, a passkey beside it or not.
    public function testARequiredAuthenticatorStaysThoughAPasskeyStandsBesideIt(): void
    {
        $company = $this->createCompany('Secure');
        $company->requireMfa(true);
        $this->createUser('someone@twes.local', self::PASSWORD, $company, authenticator: true);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->registerPasskey(new FakeAuthenticator());
        [$passkey] = $this->listedIds();

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));
        $this->getJson('/api/auth/me');
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'totp'));

        // The passkey is not the last factor while the authenticator stays, so it may go.
        $this->sendJson('DELETE', '/api/auth/mfa/passkeys/'.$passkey);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testAnOperatorsAuthenticatorStaysThoughAPasskeyStandsBesideIt(): void
    {
        $this->createUser('op@twes.local', self::PASSWORD, operator: true, authenticator: true);
        $this->login('op@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->registerPasskey(new FakeAuthenticator());

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));
    }

    public function testACompanyRequiringItKeepsTheAuthenticatorWhileAnotherIsTheOneOpen(): void
    {
        // The sign-in opens « Relaxed », first by name; « Strict » requiring it is enough, or switching would be the way round.
        $strict = $this->createCompany('Strict');
        $strict->requireMfa(true);
        $relaxed = $this->createCompany('Relaxed');
        $this->em()->flush();
        $user = $this->createUser('someone@twes.local', self::PASSWORD, $relaxed, authenticator: true);
        $this->addMembership($user, $strict);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->registerPasskey(new FakeAuthenticator());

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));
    }

    public function testAPasskeyReplacesTheRecoveryCodesOfAnAccountWhoseOnlyFactorItIs(): void
    {
        $authenticator = new FakeAuthenticator();
        $first = $this->withPasskey('someone@twes.local', $authenticator);

        $request = $this->recoveryOptions();
        self::assertSame([$authenticator->id()], array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['id'] ?? null : null, $this->arrayAt($request, 'allowCredentials')));
        $this->postJson(self::RECOVERY, ['credential' => $authenticator->assert($request)]);

        self::assertResponseIsSuccessful();
        $codes = array_values(array_filter($this->arrayAt($this->json(), 'recoveryCodes'), 'is_string'));
        self::assertCount(10, $codes);
        self::assertSame([], array_values(array_intersect($codes, $first)));
        self::assertSame(1, $this->audited('auth.recovery_codes_regenerated'));
        self::assertSame(['recovery_codes_renewed'], $this->securityNotices(), 'its owner is told by mail');

        // An old code no longer finishes a login; a new one does.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/verify', ['code' => $first[0]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]]);
        self::assertResponseIsSuccessful();
    }

    public function testReplacingTheCodesWithAPasskeyRefusesAStaleOrForeignAssertionOnTheBudgetOfFiveAttempts(): void
    {
        $theirs = new FakeAuthenticator();
        $this->withPasskey('other@twes.local', $theirs);
        $this->signOut();
        $authenticator = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $authenticator);

        // The challenge is spent by the first attempt, even a failed one, as the login's is.
        $request = $this->recoveryOptions();
        $this->postJson(self::RECOVERY, ['credential' => $authenticator->assert($request, challenge: FakeAuthenticator::base64Url(random_bytes(32)))]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));
        $this->postJson(self::RECOVERY, ['credential' => $authenticator->assert($request)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Another account's passkey proves nothing about this one.
        $this->postJson(self::RECOVERY, ['credential' => $theirs->assert($this->recoveryOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson(self::RECOVERY, ['credential' => (new FakeAuthenticator())->assert($this->recoveryOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson(self::RECOVERY, ['credential' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The sixth attempt is refused unread, however good; and none of the five replaced anything.
        $this->postJson(self::RECOVERY, ['credential' => $authenticator->assert($this->recoveryOptions())]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(0, $this->audited('auth.recovery_codes_regenerated'));
    }

    public function testReplacingTheCodesWithAPasskeyNeedsASessionAndAPasskey(): void
    {
        $this->postJson(self::RECOVERY.'/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->postJson(self::RECOVERY, ['credential' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->createUser('plain@twes.local', self::PASSWORD);
        $this->login('plain@twes.local', self::PASSWORD);
        $this->postJson(self::RECOVERY.'/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_passkey', $this->stringAt($this->json(), 'error'));
    }

    public function testEveryPasskeyEndpointThatChangesSomethingWantsTheCsrfHeader(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        foreach (['/api/auth/mfa/passkeys/options', '/api/auth/mfa/passkeys', '/api/auth/mfa/passkey-login/options', '/api/auth/mfa/passkey-login', self::RECOVERY.'/options', self::RECOVERY] as $path) {
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
        $codes = $this->registerPasskey($authenticator, $signCount);
        $this->forgetSecondFactorAttempts($email);

        return $codes;
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
        $this->stepUp(self::PASSWORD);
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

    /** @return array<string, mixed> */
    private function recoveryOptions(): array
    {
        $this->postJson(self::RECOVERY.'/options', null);
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
