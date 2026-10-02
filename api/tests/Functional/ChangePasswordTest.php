<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;

/** A signed-in person changes their own password: checked against the current one, and every session ends after it. */
final class ChangePasswordTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string NEXT = 'another-long-enough-password';
    private const string PATH = '/api/auth/password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testTheNewPasswordSignsInAndTheOldOneNoLongerDoes(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->sendJson('PUT', self::PATH, ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEXT]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the session that changed it is over too');

        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->login('someone@twes.local', self::NEXT);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(1, $this->audited('auth.password_changed'));
    }

    public function testAWrongCurrentPasswordIsRefusedAndAudited(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->sendJson('PUT', self::PATH, ['currentPassword' => 'not-the-password', 'newPassword' => self::NEXT]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('current_password', $this->stringAt($this->json(), 'error'));
        self::assertSame(1, $this->audited('auth.password_change_refused'));
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testAShortOrUnchangedPasswordIsRefusedWithItsReason(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        $this->sendJson('PUT', self::PATH, ['currentPassword' => self::PASSWORD, 'newPassword' => 'short']);
        self::assertSame('too_short', $this->stringAt($this->json(), 'error'));

        $this->sendJson('PUT', self::PATH, ['currentPassword' => self::PASSWORD, 'newPassword' => self::PASSWORD]);
        self::assertSame('unchanged', $this->stringAt($this->json(), 'error'));

        $this->sendJson('PUT', self::PATH, []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testGuessingTheCurrentPasswordSharesTheBudgetOfFiveAttempts(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            $this->sendJson('PUT', self::PATH, ['currentPassword' => 'guess-'.$i, 'newPassword' => self::NEXT]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->sendJson('PUT', self::PATH, ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEXT]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the right one does not get round the limit');
    }

    public function testSomeoneNotSignedInCannotChangeAPassword(): void
    {
        $this->createUser('someone@twes.local', self::PASSWORD);

        $this->sendJson('PUT', self::PATH, ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEXT]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function audited(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
