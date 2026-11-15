<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Account\ManageAccounts;
use App\Shared\Infrastructure\Doctrine\Rows;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * After a suspected breach every session ends at once, from the server: a session an attacker took leaves with the
 * rest. Each account is told by mail, as when an operator ends its sessions, and the journal records it against the
 * account with no one as its actor, the installation having done it.
 */
final class EndEverySessionCommandTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testEverySessionOfEveryAccountEndsAndEachIsToldOnce(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', self::PASSWORD, $company);
        $this->createUser('clerk@twes.local', self::PASSWORD, $company, ['company.read'], 'clerk');
        $this->login('owner@twes.local', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $tester = $this->endAll(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Ended the sessions of 2 accounts', $tester->getDisplay());
        // Read before the next request, which boots the kernel again and empties the queue it holds.
        self::assertSame(['operator_ended_sessions', 'operator_ended_sessions'], $this->securityNotices());
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401, 'the session opened before is over');
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT actor_user_id, changes FROM audit_log WHERE action = ?', [ManageAccounts::SESSIONS_ENDED]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNull($row['actor_user_id'], 'no account did it: the installation did');
            self::assertSame([], json_decode(Rows::text($row['changes']), true, 512, \JSON_THROW_ON_ERROR), 'the journal names, never holds an address');
        }

        $this->postJson('/api/auth/login', ['email' => 'owner@twes.local', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful('signing in again is enough');
    }

    public function testWithoutForceAScriptIsRefusedAndAPersonIsAskedFirst(): void
    {
        $this->createUser('owner@twes.local', self::PASSWORD, $this->createCompany('Acme'));

        $script = $this->endAll([]);
        self::assertSame(Command::INVALID, $script->getStatusCode());
        self::assertStringContainsString('--force', $script->getDisplay());

        $declined = $this->endAll([], interactive: true, answers: ['no']);
        self::assertSame(Command::FAILURE, $declined->getStatusCode());
        self::assertSame([], $this->securityNotices(), 'nothing ended, nobody told');

        $confirmed = $this->endAll([], interactive: true, answers: ['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->getStatusCode(), $confirmed->getDisplay());
        self::assertSame(['operator_ended_sessions'], $this->securityNotices());
    }

    /**
     * @param array<string, bool> $arguments
     * @param list<string>        $answers
     */
    private function endAll(array $arguments, bool $interactive = false, array $answers = []): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:sessions:end-all'));
        $tester->setInputs($answers);
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }
}
