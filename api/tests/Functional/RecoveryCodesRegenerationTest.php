<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new set of recovery codes for an account that has used or lost its set (ruling of 2026-09-10: shown once,
 * regenerable with a current factor). The proof is a code from the authenticator and never a recovery code, or one
 * leaked code would be enough to replace all ten.
 */
final class RecoveryCodesRegenerationTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string PATH = '/api/auth/mfa/recovery-codes';

    private string $secret = '';
    private string $confirmingCode = '';

    /** @var list<string> */
    private array $firstCodes = [];

    public function testACurrentAuthenticatorCodeIssuesANewSetAndRetiresTheOldOne(): void
    {
        $this->enrolledAndSignedIn();

        $this->postJson(self::PATH, ['code' => $this->nextCode()]);

        self::assertResponseIsSuccessful();
        $codes = $this->codesIn($this->json());
        self::assertCount(10, $codes);
        self::assertSame([], array_values(array_intersect($codes, $this->firstCodes)));
        self::assertSame(1, $this->audited('auth.recovery_codes_regenerated'));

        // An old code no longer finishes a login; a new one does.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->firstCodes[1]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->postJson('/api/auth/mfa/verify', ['code' => $codes[0]]);
        self::assertResponseIsSuccessful();
    }

    public function testARecoveryCodeIsNotProofEnoughToReplaceTheSet(): void
    {
        $this->enrolledAndSignedIn();

        $this->postJson(self::PATH, ['code' => $this->firstCodes[0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->stringAt($this->json(), 'error'));
        self::assertSame(0, $this->audited('auth.recovery_codes_regenerated'));

        // The set it tried to replace is untouched, and the code it offered is not spent either.
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->firstCodes[0]]);
        self::assertResponseIsSuccessful();
    }

    public function testTheCodeThatConfirmedTheAuthenticatorCannotBeSpentAgain(): void
    {
        $this->enrolledAndSignedIn();

        $this->postJson(self::PATH, ['code' => $this->confirmingCode]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->audited('auth.recovery_codes_regenerated'));
    }

    public function testAnAccountWithoutAnAuthenticatorHasNothingToRegenerate(): void
    {
        $this->createUser('plain@twes.local', self::PASSWORD);
        $this->login('plain@twes.local', self::PASSWORD);

        $this->postJson(self::PATH, ['code' => '123456']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->stringAt($this->json(), 'error'));
    }

    /**
     * Guessing here is not merely paced, it is stopped, exactly as at login: these are the same six digits, and a
     * guess that lands hands over all ten codes (docs/SPEC.md § 8 row 22, review S6).
     */
    public function testGuessingLocksTheAccountAsItDoesAtLogin(): void
    {
        $this->enrolledAndSignedIn();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->postJson(self::PATH, ['code' => '000000']);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->postJson(self::PATH, ['code' => $this->nextCode()]);

        // The lock answers, not the limiter: once the account is locked even the right code buys nothing.
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('account_locked', $this->stringAt($this->json(), 'error'));
        self::assertSame(0, $this->audited('auth.recovery_codes_regenerated'));
    }

    public function testItNeedsASession(): void
    {
        $this->postJson(self::PATH, ['code' => '123456']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** Enrols through the real endpoints, so the first set of codes and the spent timestep are the product's own. */
    private function enrolledAndSignedIn(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/enrolment', []);
        $this->secret = $this->stringAt($this->json(), 'secret');
        $this->confirmingCode = (new OtphpTotpCodes())->codeAt($this->secret, new \DateTimeImmutable());
        $this->postJson('/api/auth/mfa/enrolment/confirm', ['code' => $this->confirmingCode]);
        self::assertResponseIsSuccessful();
        $this->firstCodes = $this->codesIn($this->json());
    }

    /** The next step's code: the confirming step is spent, and one step of leeway admits this one. */
    private function nextCode(): string
    {
        return (new OtphpTotpCodes())->codeAt($this->secret, new \DateTimeImmutable('+30 seconds'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function codesIn(array $body): array
    {
        $codes = [];
        foreach ($this->arrayAt($body, 'recoveryCodes') as $code) {
            self::assertIsString($code);
            $codes[] = $code;
        }

        return $codes;
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
