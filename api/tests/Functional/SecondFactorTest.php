<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\RecoveryCode;
use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second factor, end to end.
 *
 * The rule this whole design exists to satisfy is the first test below: a password-only session is refused
 * everywhere, and it is refused because no session was ever created — not because a check was remembered at
 * each endpoint. Nothing in `access_control` or in any voter knows about MFA (ruling of 2026-09-10).
 */
final class SecondFactorTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';

    /** The same key api/.env.test gives the application, so a fixture encrypts what production can read. */
    private const string TEST_KEY = 'Fh0hAlB8Q7xUq0mJ0zRz2s4vXn6yKbPd8eGtWc3AjQY=';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testAPasswordAloneBuysNothingWhenASecondFactorIsEnrolled(): void
    {
        $this->enrolledUser('someone@twes.local');

        $this->login('someone@twes.local', self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));

        // The point of the whole exercise: no session was created, so everything behind the firewall refuses.
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->getJson('/api/me/companies');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheCodeCompletesTheLogin(): void
    {
        $this->enrolledUser('someone@twes.local');
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/verify', ['code' => $this->currentCode()]);

        self::assertResponseIsSuccessful();
        // Completing the second factor answers exactly what a one-step login answers: the same handler runs.
        self::assertSame('someone@twes.local', $this->stringAt($this->section($this->json(), 'user'), 'email'));

        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testAWrongCodeDoesNotCompleteIt(): void
    {
        $this->enrolledUser('someone@twes.local');
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/verify', ['code' => '000000']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testACodeIsNotAcceptedTwice(): void
    {
        $this->enrolledUser('someone@twes.local');
        $this->login('someone@twes.local', self::PASSWORD);
        $code = $this->currentCode();

        $this->postJson('/api/auth/mfa/verify', ['code' => $code]);
        self::assertResponseIsSuccessful();

        // Same code, same window, second session: still valid arithmetically, and still refused.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/verify', ['code' => $code]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testVerifyingWithoutHavingPassedThePasswordIsRefused(): void
    {
        $this->enrolledUser('someone@twes.local');

        // No login first: there is no pending second factor to complete, so the code is worth nothing.
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->currentCode()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAUserWithNoSecondFactorLogsInAsBefore(): void
    {
        $this->createUser('plain@twes.local', self::PASSWORD);

        $this->login('plain@twes.local', self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('mfaRequired', $this->json());
        self::assertSame('plain@twes.local', $this->stringAt($this->section($this->json(), 'user'), 'email'));
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testARecoveryCodeAlsoCompletesTheLoginAndIsThenSpent(): void
    {
        $codes = $this->enrolledUser('someone@twes.local');
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]]);
        self::assertResponseIsSuccessful();

        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnotherAccountSigningInClosesTheSecondFactorTheFirstOwed(): void
    {
        $this->enrolledUser('someone@twes.local');
        $this->createUser('other@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));

        // Same browser, a different account signs in: the first account's half-login must not survive it.
        $this->login('other@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/auth/mfa/verify', ['code' => $this->currentCode()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('mfa_not_pending', $this->stringAt($this->json(), 'error'));
    }

    public function testAFailedPasswordAttemptClosesTheSecondFactorAnEarlierOneOwed(): void
    {
        $this->enrolledUser('someone@twes.local');
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));

        $this->login('someone@twes.local', 'not-the-password');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->postJson('/api/auth/mfa/verify', ['code' => $this->currentCode()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('mfa_not_pending', $this->stringAt($this->json(), 'error'));
    }

    /**
     * Enrols a user the way the use cases will, and hands back the raw recovery codes.
     *
     * @return list<string>
     */
    private function enrolledUser(string $email): array
    {
        $user = $this->createUser($email, self::PASSWORD);
        $codes = new OtphpTotpCodes();

        // Built here rather than pulled from the container: these are ports, so nothing injects them until
        // the use cases do, and the test container prunes what nothing injects. The key is .env.test's.
        $this->secret = $codes->generateSecret();
        $user->beginTotpEnrolment((new SodiumSecretCipher(self::TEST_KEY))->encrypt($this->secret));

        // Confirmed one step in the past, so the code these tests use now is newer than the one already spent.
        $earlier = new \DateTimeImmutable('-30 seconds');
        $user->confirmTotpEnrolment($codes->verify($this->secret, $codes->codeAt($this->secret, $earlier), $earlier) ?? 0);

        $raw = [];
        foreach (RecoveryCode::generateSet() as $code) {
            $raw[] = $code->raw;
            $this->em()->persist(new RecoveryCodeEntry($user, $code->hash()));
        }
        $this->em()->flush();

        return $raw;
    }

    private function currentCode(): string
    {
        return (new OtphpTotpCodes())->codeAt($this->secret, new \DateTimeImmutable());
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }
}
