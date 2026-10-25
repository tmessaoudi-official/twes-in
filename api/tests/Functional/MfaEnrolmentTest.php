<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use App\Tenancy\Domain\Model\Company;
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

    public function testThePathSpelledWithPercentEscapesIsHeldToo(): void
    {
        $this->createUser('op@twes.local', self::PASSWORD, operator: true, authenticator: false);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        foreach (['/%61pi/platform/companies', '/api/%70latform/companies'] as $path) {
            $this->getJson($path);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path);
            self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'), $path);
        }
    }

    public function testStartingAnAuthenticatorWaitsForAFreshProofOfWhoIsAtTheScreen(): void
    {
        // A session taken from someone must not let its taker plant a factor of their own and keep the account.
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseIsSuccessful();
    }

    public function testAnAccountHeldForWantOfAFactorCanProveItselfAndEnrol(): void
    {
        $company = $this->createCompany('Secure');
        $company->requireMfa(true);
        $this->createUser('someone@twes.local', self::PASSWORD, $company);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseIsSuccessful();
    }

    public function testEnrolmentHandsBackASecretAndAUriAnAuthenticatorCanRead(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->stepUp(self::PASSWORD);
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
        $this->stepUp(self::PASSWORD);
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
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');

        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);

        self::assertResponseIsSuccessful();
        self::assertCount(10, $this->arrayAt($this->json(), 'recoveryCodes'));
        self::assertSame(['authenticator_added'], $this->securityNotices(), 'its owner is told by mail');

        // And it is in force from the next sign-in.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
    }

    /**
     * A pending secret sealed under a key since rotated cannot be read: confirming is refused as a wrong code would be,
     * not answered with a 500, and starting again seals a new secret under the current key (docs/SPEC.md § 8 row 31).
     */
    public function testConfirmingAPendingSecretTheCurrentKeyCannotReadIsRefusedAndStartingAgainWorks(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');
        $sealedElsewhere = (new SodiumSecretCipher('AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='))->encrypt($secret);
        $this->em()->getConnection()->executeStatement('UPDATE "user" SET totp_secret = ? WHERE email = ?', [$sealedElsewhere, 'someone@twes.local']);

        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->stringAt($this->json(), 'error'));

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $fresh = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($fresh, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();
    }

    public function testGuessingTheEnrolmentCodeMeetsTheSecondFactorsBudget(): void
    {
        // A guess that lands here installs an authenticator and hands over its recovery codes: the sign-in's budget.
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => '000000']);
        }

        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(['error' => 'too_many_attempts'], $this->json());
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertArrayNotHasKey('mfaRequired', $this->json());
    }

    public function testConfirmingWithAWrongCodeLeavesItOff(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
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
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $secret = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();

        // A session is all this endpoint asks for, so a forgotten or stolen one must not be able to turn the factor off.
        $this->stepUp(self::PASSWORD);
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

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseIsSuccessful();
        $secret = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();

        // Enrolled: the rest of the API opens up.
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful();
    }

    // docs/SPEC.md § 7, 2026-09-22 09:12: every second factor is manageable, the last one a company requires is kept,
    // and removing one asks for a fresh proof first.
    public function testAnAuthenticatorIsRemovedAfterAFreshProofAndCanBeEnrolledAgain(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD, authenticator: true);
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        // The code that finished the sign-in is not the proof: a taken session must not strip the account's factors.
        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));

        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['authenticator_removed'], $this->securityNotices(), 'its owner is told by mail');
        $this->getJson('/api/auth/me');
        self::assertFalse($this->boolAt($this->section($this->json(), 'mfa'), 'totp'));
        self::assertSame(1, $this->audited('auth.totp_removed'));

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('totp_not_enrolled', $this->stringAt($this->json(), 'error'));

        // Replacing a phone is removing its authenticator and enrolling the new one.
        $this->postJson('/api/auth/mfa/enrolment', []);
        self::assertResponseIsSuccessful();
        $secret = $this->stringAt($this->json(), 'secret');
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();
        $this->getJson('/api/auth/me');
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'totp'));
    }

    public function testTheAuthenticatorACompanyRequiresIsKept(): void
    {
        $company = $this->createCompany('Secure');
        $company->requireMfa(true);
        $this->createUser('someone@twes.local', self::PASSWORD, $company, authenticator: true);
        $this->login('someone@twes.local', self::PASSWORD);

        // Refused before any proof is asked: a proof given for a removal that cannot happen would be asked for nothing.
        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));

        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));
        self::assertSame([], $this->securityNotices(), 'nothing changed, so nothing is mailed');
        $this->getJson('/api/auth/me');
        self::assertTrue($this->boolAt($this->section($this->json(), 'mfa'), 'totp'));
    }

    public function testAnOperatorKeepsTheirAuthenticator(): void
    {
        // Every operator carries a second factor, whatever company they belong to (docs/SPEC.md § 7, 2026-09-15, S3).
        $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('mfa_authenticator_required', $this->stringAt($this->json(), 'error'));
    }

    public function testRemovingAnAuthenticatorNeedsASession(): void
    {
        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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
