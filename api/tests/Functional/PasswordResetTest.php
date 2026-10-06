<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Password\PasswordResetAsked;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/** Forgot password with no session: a mailed single-use link, answered the same whatever the address. */
final class PasswordResetTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string NEXT = 'another-long-enough-password';
    private const string FORGOT = '/api/auth/password/forgot';
    private const string RESET = '/api/auth/password/reset';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testTheMailedLinkChoosesANewPasswordOnceAndEndsTheSessions(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->postJson(self::FORGOT, ['email' => 'someone@twes.local']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->deliverQueued();
        $token = $this->tokenInMail();

        $this->postJson(self::RESET, ['token' => $token, 'newPassword' => self::NEXT]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the session open before the reset is over');
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->login('someone@twes.local', self::NEXT);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->postJson(self::RESET, ['token' => $token, 'newPassword' => 'yet-another-long-password']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('link_not_usable', $this->stringAt($this->json(), 'error'), 'a link works once');
    }

    public function testAnUnknownAddressIsAnsweredLikeAKnownOneAndMailsNothing(): void
    {
        $this->postJson(self::FORGOT, ['email' => 'nobody@twes.local']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEquals([new PasswordResetAsked('nobody@twes.local', null)], $this->queued(), 'queued as a known one is');
        self::assertSame(1, $this->deliverQueued());
        self::assertEmailCount(0);
    }

    public function testTheRequestOnlyQueuesTheAskAndTheWorkerMakesTheLinkAndMailsIt(): void
    {
        // Audit D-1: the answer must not tell a registered address by the time it takes, so the request does the same
        // for every address and the token is made where nobody times it; the queue carries no token either.
        $user = $this->createUser('someone@twes.local', self::PASSWORD);
        $this->postJson(self::FORGOT, ['email' => 'someone@twes.local', 'locale' => 'en']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEquals([new PasswordResetAsked('someone@twes.local', 'en')], $this->queued());
        self::assertSame(0, $this->resetsOf($user->getId()->toRfc4122()), 'no link yet');
        self::assertEmailCount(0);

        $this->deliverQueued();

        self::assertSame(1, $this->resetsOf($user->getId()->toRfc4122()));
        self::assertEmailCount(1);
    }

    public function testAMalformedAddressIsRefusedAndASecondAskInTheQuarterHourSendsNothing(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->postJson(self::FORGOT, ['email' => 'not-an-address']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson(self::FORGOT, ['email' => 'someone@twes.local']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->deliverQueued();
        self::assertEmailCount(1);

        $this->postJson(self::FORGOT, ['email' => 'someone@twes.local']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'the same answer');
        self::assertSame(0, $this->deliverQueued());
        self::assertEmailCount(0);
    }

    public function testAnUnknownLinkAndAWeakPasswordAreRefusedWithTheirReasons(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);

        $this->postJson(self::RESET, ['token' => str_repeat('0', 64), 'newPassword' => self::NEXT]);
        self::assertSame('link_not_usable', $this->stringAt($this->json(), 'error'));

        $this->postJson(self::FORGOT, ['email' => 'someone@twes.local']);
        $this->deliverQueued();
        $token = $this->tokenInMail();
        $this->postJson(self::RESET, ['token' => $token, 'newPassword' => 'short']);
        self::assertSame('too_short', $this->stringAt($this->json(), 'error'));

        $this->postJson(self::RESET, ['token' => $token, 'newPassword' => self::NEXT]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'a refused password leaves the link usable');
    }

    private function resetsOf(string $userId): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM password_reset WHERE user_id = ?', [$userId]);
        self::assertIsInt($count);

        return $count;
    }

    private function tokenInMail(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('#/reset-password/([0-9a-f]{64})#', (string) $message->getHtmlBody(), $found), 'the mail carries the link');

        return $found[1] ?? self::fail('the link has no token');
    }
}
