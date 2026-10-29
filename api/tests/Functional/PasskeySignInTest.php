<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Login\PasskeySignInSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in with a passkey alone: each person switches it on for their own account, only beside the authenticator
 * app, which stays the way back; the installation's operators may keep it off for everyone.
 */
final class PasskeySignInTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string EMAIL = 'someone@twes.local';
    private const string SWITCH = '/api/auth/mfa/passkey-sign-in';
    private const string OPTIONS = '/api/auth/passkey-sign-in/options';
    private const string SIGN_IN = '/api/auth/passkey-sign-in';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testItIsOffUntilItsOwnerSwitchesItOnAfterProvingWhoTheyAre(): void
    {
        $this->signedInWithAuthenticator();
        $this->registerPasskey(new FakeAuthenticator(), residentKey: true);
        self::assertFalse($this->switchState(), 'off by default, for every account');

        $this->forgetStepUp();
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['passkey_sign_in_on'], $this->securityNotices(), 'its owner is told by mail');
        self::assertSame(1, $this->audited('auth.passkey_sign_in_on'));
        self::assertTrue($this->switchState());
    }

    public function testItIsOfferedOnlyBesideTheAuthenticatorAndAPasskeyThatCanSignInAlone(): void
    {
        $this->createUser(self::EMAIL, self::PASSWORD);
        $this->login(self::EMAIL, self::PASSWORD);
        $this->registerPasskey(new FakeAuthenticator(), residentKey: true);

        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('passkey_sign_in_needs_authenticator', $this->json()['error'] ?? null);
    }

    public function testAPasskeyTheBrowserKeptOffTheDeviceCannotBeTheWayIn(): void
    {
        $this->signedInWithAuthenticator();
        $this->registerPasskey(new FakeAuthenticator(), residentKey: false);

        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('passkey_sign_in_needs_passkey', $this->json()['error'] ?? null);

        // A passkey made before the browser was asked says nothing either way, and may well live on the device.
        $this->registerPasskey(new FakeAuthenticator(), residentKey: null);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testTheInstallationsOperatorsMayKeepItOffForEveryone(): void
    {
        $this->signedInWithAuthenticator();
        $this->registerPasskey(new FakeAuthenticator(), residentKey: true);
        $this->getJson('/api/auth/me');
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'passkeySignInAllowed'));
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), PasskeySignInSettings::ALLOWED, SettingLevel::Platform, false, null);

        $this->getJson('/api/auth/me');
        self::assertFalse($this->boolAt($this->section($this->json(), 'mfa'), 'passkeySignInAllowed'));
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('passkey_sign_in_off', $this->json()['error'] ?? null);
    }

    public function testSwitchingItOffAsksNoProofAndTellsItsOwnerOnce(): void
    {
        $this->signedInWithAuthenticator();
        $this->registerPasskey(new FakeAuthenticator(), residentKey: true);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->forgetStepUp();

        $this->sendJson('PUT', self::SWITCH, ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['passkey_sign_in_off'], $this->securityNotices());
        $this->sendJson('PUT', self::SWITCH, ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->securityNotices(), 'nothing changed, nothing is told');
        self::assertSame(1, $this->audited('auth.passkey_sign_in_off'));
        self::assertFalse($this->switchState());
    }

    public function testTheSwitchNeedsASessionAndAYesOrNo(): void
    {
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->signedInWithAuthenticator();
        $this->sendJson('PUT', self::SWITCH, ['enabled' => 'yes']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAPasskeyAloneSignsInAnAccountWhoseOwnerSwitchedItOn(): void
    {
        $authenticator = $this->switchedOn();

        $options = $this->signInOptions();
        self::assertSame([], $options['allowCredentials'] ?? [], 'no address is named, so none is revealed');
        self::assertSame('required', $options['userVerification'] ?? null);
        $this->postJson(self::SIGN_IN, ['credential' => $authenticator->assert($options, withUserHandle: true)]);

        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->section($this->json(), 'user')['email'] ?? null);
        self::assertArrayNotHasKey('mfaRequired', $this->json(), 'the passkey with its user verification is both steps');
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testALockedPasswordDoesNotStopAPasskey(): void
    {
        $authenticator = $this->switchedOn();
        $this->em()->getConnection()->executeStatement('UPDATE "user" SET locked_until = ? WHERE email = ?', [new \DateTimeImmutable('+1 hour')->format('Y-m-d H:i:s'), self::EMAIL]);

        $this->postJson(self::SIGN_IN, ['credential' => $authenticator->assert($this->signInOptions(), withUserHandle: true)]);

        self::assertResponseIsSuccessful();
    }

    /**
     * Whatever stops it, the screen hears the same words: it never learns that an account exists, holds this passkey,
     * or signs in this way. The trail keeps why.
     *
     * @return iterable<string, array{\Closure(self, FakeAuthenticator): array<string, mixed>}>
     */
    public static function signInRefusals(): iterable
    {
        yield 'a passkey nobody registered' => [static fn (self $test, FakeAuthenticator $_): array => new FakeAuthenticator()->assert($test->signInOptions(), withUserHandle: true)];
        yield 'no proof that its holder is there' => [static fn (self $test, FakeAuthenticator $known): array => $known->assert($test->signInOptions(), FakeAuthenticator::USER_PRESENT, withUserHandle: true)];
        yield 'another account named as its owner' => [static fn (self $test, FakeAuthenticator $known): array => $known->assert($test->signInOptions(), withUserHandle: true, userHandle: FakeAuthenticator::base64Url('someone-else'))];
        yield 'no owner named at all' => [static fn (self $test, FakeAuthenticator $known): array => $known->assert($test->signInOptions())];
        yield 'a challenge it did not ask for' => [static fn (self $test, FakeAuthenticator $known): array => $known->assert($test->signInOptions(), challenge: FakeAuthenticator::base64Url(random_bytes(32)), withUserHandle: true)];
        yield 'its owner switched it off' => [static function (self $test, FakeAuthenticator $known): array {
            $test->em()->getConnection()->executeStatement('UPDATE "user" SET passkey_sign_in = false WHERE email = ?', [self::EMAIL]);

            return $known->assert($test->signInOptions(), withUserHandle: true);
        }];
        yield 'the installation keeps it off' => [static function (self $test, FakeAuthenticator $known): array {
            static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), PasskeySignInSettings::ALLOWED, SettingLevel::Platform, false, null);

            return $known->assert($test->signInOptions(), withUserHandle: true);
        }];
        yield 'its owner removed the authenticator app' => [static function (self $test, FakeAuthenticator $known): array {
            $test->em()->getConnection()->executeStatement('UPDATE "user" SET totp_secret = NULL, totp_confirmed_at = NULL WHERE email = ?', [self::EMAIL]);

            return $known->assert($test->signInOptions(), withUserHandle: true);
        }];
    }

    /** @param \Closure(self, FakeAuthenticator): array<string, mixed> $credential */
    #[\PHPUnit\Framework\Attributes\DataProvider('signInRefusals')]
    public function testEveryRefusalAnswersTheSame(\Closure $credential): void
    {
        $known = $this->switchedOn();

        $this->postJson(self::SIGN_IN, ['credential' => $credential($this, $known)]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(['error' => 'invalid_passkey'], $this->json());
        self::assertSame(1, $this->audited('auth.passkey_sign_in_refused'));
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAChallengeAnswersOnce(): void
    {
        $authenticator = $this->switchedOn();
        $credential = $authenticator->assert($this->signInOptions(), withUserHandle: true);
        $this->postJson(self::SIGN_IN, ['credential' => ['id' => 'nothing']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->postJson(self::SIGN_IN, ['credential' => $credential]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testASwitchedOffAccountIsToldWhyOnceThePasskeyIsProved(): void
    {
        $authenticator = $this->switchedOn();
        $this->em()->getConnection()->executeStatement('UPDATE "user" SET is_active = false WHERE email = ?', [self::EMAIL]);

        $this->postJson(self::SIGN_IN, ['credential' => $authenticator->assert($this->signInOptions(), withUserHandle: true)]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('account_disabled', $this->json()['error'] ?? null);
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testOneAddressHasABudgetOfTries(): void
    {
        $limit = static::getContainer()->getParameter('app.rate_limit.passkey_sign_in.limit');
        try {
            for ($try = 0; $try < $limit; ++$try) {
                $this->postJson(self::SIGN_IN, ['credential' => ['id' => 'nothing']]);
                self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            }
            $this->postJson(self::SIGN_IN, ['credential' => ['id' => 'nothing']]);

            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        } finally {
            static::getContainer()->get('limiter.passkey_sign_in')->create('127.0.0.1')->reset();
        }
    }

    /** @return array<string, mixed> */
    public function signInOptions(): array
    {
        $this->postJson(self::OPTIONS, null);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** An account whose owner let a passkey alone sign it in, and a browser that has signed out since. */
    private function switchedOn(): FakeAuthenticator
    {
        $authenticator = new FakeAuthenticator();
        $this->signedInWithAuthenticator();
        $this->registerPasskey($authenticator, residentKey: true);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', self::SWITCH, ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->getCookieJar()->clear();

        return $authenticator;
    }

    private function signedInWithAuthenticator(): void
    {
        $this->createUser(self::EMAIL, self::PASSWORD, authenticator: true);
        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function registerPasskey(FakeAuthenticator $authenticator, ?bool $residentKey): void
    {
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json(), residentKey: $residentKey)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function switchState(): bool
    {
        $this->getJson('/api/auth/me');

        return $this->boolAt($this->section($this->json(), 'mfa'), 'passkeySignIn');
    }

    /** A new sign-in carries no proof of who is at the screen: the second factor finishes the login, it is not the step-up. */
    private function forgetStepUp(): void
    {
        $this->client->getCookieJar()->clear();
        $this->login(self::EMAIL, self::PASSWORD);
    }

    private function audited(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
