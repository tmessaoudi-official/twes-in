<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enrolling a second factor, and what a company that requires one does to a member who has not.
 *
 * Enrolment is two steps because a mis-scanned QR code must not be able to enable a factor nobody can
 * satisfy: the secret stays pending until one real code proves the authenticator agrees.
 */
final class MfaEnrolmentTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testAnOperatorWithoutASecondFactorIsHeldUntilTheyEnrol(): void
    {
        // An operator account must carry a second factor, whatever company they belong to (docs/SPEC.md § 7, 2026-09-15, S3).
        $this->createUser('op@twes.local', self::PASSWORD, operator: true, authenticator: false);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/platform/companies');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'));

        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'required'));
    }

    public function testEnrolmentHandsBackASecretAndAUriAnAuthenticatorCanRead(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/enrolment', []);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $this->stringAt($body, 'secret'));
        self::assertStringContainsString('otpauth://totp/', $this->stringAt($body, 'provisioningUri'));
        self::assertStringContainsString($this->stringAt($body, 'secret'), $this->stringAt($body, 'provisioningUri'));
    }

    public function testAPendingEnrolmentIsNotYetInForce(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);

        // Signing in again must not ask for a code: nothing has been confirmed.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('mfaRequired', $this->json());
    }

    public function testConfirmingTurnsItOnAndHandsBackTheRecoveryCodesOnce(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');

        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);

        self::assertResponseIsSuccessful();
        self::assertCount(10, $this->arrayAt($this->json(), 'recoveryCodes'));

        // And it is in force from the next sign-in.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
    }

    public function testConfirmingWithAWrongCodeLeavesItOff(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);

        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => '000000']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertArrayNotHasKey('mfaRequired', $this->json());
    }

    public function testAnAuthenticatorInForceCannotBeReplacedByStartingAgain(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();

        // A session is all this endpoint asks for, so a forgotten or stolen one must not be able to turn the factor off.
        $this->postJson('/api/auth/mfa/enrolment', []);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_already_enrolled', $this->stringAt($this->json(), 'error'));

        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
    }

    public function testEnrolmentNeedsASession(): void
    {
        $this->postJson('/api/auth/mfa/enrolment', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeSaysWhetherAFactorIsEnrolledAndWhetherOneIsRequired(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->getJson('/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->boolAt($this->section($this->json(), 'mfa'), 'enrolled'));
        self::assertFalse($this->boolAt($this->section($this->json(), 'mfa'), 'required'));
    }

    public function testACompanyThatRequiresAFactorBlocksAMemberWhoHasNoneUntilTheyEnrol(): void
    {
        $company = $this->createCompany('Strict');
        $company->requireMfa(true);
        $this->em()->flush();
        $this->createUser('someone@twes.local', self::PASSWORD, $company);
        $this->login('someone@twes.local', self::PASSWORD);

        // Everything else is refused, and says why rather than looking like a permission problem.
        $this->getJson('/api/me/companies');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'));

        // ... but the way out stays open: /me tells the SPA what is wrong, and enrolment works.
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'required'));

        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseIsSuccessful();
        $secret = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();

        // Enrolled: the rest of the API opens up.
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful();
    }

    public function testTheRequirementFollowsTheUserNotTheChosenCompany(): void
    {
        // A member of one strict company and one relaxed one must enrol either way, or the company switcher
        // would be the way around it (ruling of 2026-09-10).
        $strict = $this->createCompany('Strict');
        $strict->requireMfa(true);
        $relaxed = $this->createCompany('Relaxed');
        $this->em()->flush();

        $user = $this->createUser('someone@twes.local', self::PASSWORD, $relaxed);
        $this->addMembership($user, $strict);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->getJson('/api/auth/me');
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'required'));
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }
}
