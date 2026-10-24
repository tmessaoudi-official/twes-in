<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Model\UserSession;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moving an authenticator to a new phone or app without ever leaving the account with no factor: the new secret waits
 * beside the one in force until a code from the new app proves it was read, then takes its place in one write, with a
 * new set of recovery codes; every other session ends. An operator, whose last factor can never be removed, is the
 * account this exists for: before it, the only way to change apps was the server's `app:mfa:replace`.
 */
final class AuthenticatorReplacementTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string EMAIL = 'op@twes.local';
    private const string BEGIN = '/api/auth/mfa/authenticator/replacement';
    private const string CONFIRM = '/api/auth/mfa/authenticator/replacement/confirm';

    public function testReplacingWaitsForAFreshProofOfWhoIsAtTheScreen(): void
    {
        // A session taken from someone must not let its taker swap the owner's authenticator for one of their own.
        $this->operatorSignedIn();

        $this->postJson(self::BEGIN, []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
    }

    public function testAnAccountWithNoAuthenticatorHasNothingToReplace(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->postJson(self::BEGIN, []);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('totp_not_enrolled', $this->stringAt($this->json(), 'error'));
    }

    public function testTheNewSecretWaitsBesideTheOneInForce(): void
    {
        $this->operatorSignedIn();
        $this->stepUp(self::PASSWORD);

        $this->postJson(self::BEGIN, []);

        self::assertResponseIsSuccessful();
        $secret = $this->stringAt($this->json(), 'secret');
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertNotSame(self::AUTHENTICATOR_SECRET, $secret);
        self::assertStringContainsString($secret, $this->stringAt($this->json(), 'provisioningUri'));

        // Nothing changed yet: the app in force still signs in, and the new one does not.
        $this->signOut();
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->codeOf($secret)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->forgetSecondFactorAttempts(self::EMAIL);
        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->audited('auth.totp_replaced'));
    }

    public function testACodeFromTheNewAppPutsItInForceWithNewRecoveryCodes(): void
    {
        $this->operatorSignedIn();
        $before = $this->recoveryCodesFor(self::EMAIL);
        $secret = $this->begun();

        $this->postJson(self::CONFIRM, ['code' => $this->codeOf($secret)]);

        self::assertResponseIsSuccessful();
        $codes = $this->arrayAt($this->json(), 'recoveryCodes');
        self::assertCount(10, $codes);
        self::assertNotSame($before, $this->recoveryCodesFor(self::EMAIL));
        self::assertSame(1, $this->audited('auth.totp_replaced'));

        // The old app no longer signs in; the new one does.
        $this->signOut();
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->postJson('/api/auth/mfa/verify', ['code' => (new OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, new \DateTimeImmutable('+30 seconds'))]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->forgetSecondFactorAttempts(self::EMAIL);
        $this->postJson('/api/auth/mfa/verify', ['code' => $this->codeOf($secret, '+30 seconds')]);
        self::assertResponseIsSuccessful();
    }

    public function testACodeOfTheOldAppDoesNotConfirmTheNewOne(): void
    {
        $this->operatorSignedIn();
        $this->begun();

        $this->postJson(self::CONFIRM, ['code' => (new OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, new \DateTimeImmutable('+30 seconds'))]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->stringAt($this->json(), 'error'));
        self::assertSame(0, $this->audited('auth.totp_replaced'));
    }

    public function testNothingPendingConfirmsNothing(): void
    {
        $this->operatorSignedIn();

        $this->postJson(self::CONFIRM, ['code' => (new OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, new \DateTimeImmutable('+30 seconds'))]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->stringAt($this->json(), 'error'));
    }

    public function testTheSameCodeCannotConfirmTwice(): void
    {
        $this->operatorSignedIn();
        $secret = $this->begun();
        $code = $this->codeOf($secret);
        $this->postJson(self::CONFIRM, ['code' => $code]);
        self::assertResponseIsSuccessful();

        $this->postJson(self::CONFIRM, ['code' => $code]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, $this->audited('auth.totp_replaced'));
    }

    public function testEveryOtherSessionEndsAndThisOneStays(): void
    {
        $this->operatorSignedIn();
        $user = $this->userOf(self::EMAIL);
        $elsewhere = new UserSession($user, 'a-session-on-the-lost-phone', 'phone', '192.0.2.7', new \DateTimeImmutable());
        $this->em()->persist($elsewhere);
        $this->em()->flush();
        $secret = $this->begun();

        $this->postJson(self::CONFIRM, ['code' => $this->codeOf($secret)]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $other = $this->em()->getRepository(UserSession::class)->find($elsewhere->getId());
        self::assertInstanceOf(UserSession::class, $other);
        self::assertTrue($other->isRevoked());
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testStartingAgainReplacesTheSecretStillWaiting(): void
    {
        $this->operatorSignedIn();
        $first = $this->begun();
        $second = $this->begun();
        self::assertNotSame($first, $second);

        $this->postJson(self::CONFIRM, ['code' => $this->codeOf($first)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson(self::CONFIRM, ['code' => $this->codeOf($second)]);
        self::assertResponseIsSuccessful();
    }

    public function testRemovingTheAuthenticatorDropsTheSecretWaiting(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD, authenticator: true);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson(self::BEGIN, []);
        self::assertResponseIsSuccessful();

        $this->sendJson('DELETE', '/api/auth/mfa/authenticator');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->em()->clear();
        self::assertNull($this->userOf('someone@twes.local')->getTotpReplacementSecret());
    }

    private function operatorSignedIn(): void
    {
        $this->createUser(self::EMAIL, self::PASSWORD, operator: true);
        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** Starts a replacement behind a fresh proof and hands back the new secret. */
    private function begun(): string
    {
        $this->stepUp(self::PASSWORD);
        $this->postJson(self::BEGIN, []);
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->json(), 'secret');
    }

    private function codeOf(string $secret, string $when = 'now'): string
    {
        return (new OtphpTotpCodes())->codeAt($secret, new \DateTimeImmutable($when));
    }

    private function userOf(string $email): User
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString($email)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return list<string> the stored digests, which change when a new set is issued */
    private function recoveryCodesFor(string $email): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT r.code_hash FROM recovery_code r JOIN "user" u ON u.id = r.user_id WHERE u.email = ? ORDER BY 1',
            [$email],
        );

        return array_map(static fn (mixed $hash): string => \is_string($hash) ? $hash : '', $rows);
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function audited(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
