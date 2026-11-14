<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * What a shop's installation script reads each quarter hour of the sign-ins refused (scripts/onprem/twes), to mail the
 * developer a burst of guesses or of locked accounts: counted from the journal as the sign-in writes it, from the moment
 * the script names.
 */
final class InstallationSignInsTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    public function testTheRefusedSignInsSinceAMomentAreCountedWithTheOnesThatLockedAnAccount(): void
    {
        $this->createUser('owner@shop.test', self::PASSWORD, $this->createCompany('Quincaillerie'));
        $this->em()->flush();
        // An hour and a half ago, before the moment asked: not counted.
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO audit_log (id, company_id, entity_type, entity_id, action, actor_user_id, changes, at, ip) VALUES (gen_random_uuid(), NULL, 'user', NULL, 'auth.login_failed', NULL, '{\"locked\": true}', ?, NULL)",
            [self::apiNow('-90 minutes')],
        );

        // Five wrong passwords lock the account (app.auth.lock_after_failures); a sixth meets the lock and locks nothing.
        for ($i = 0; $i < 6; ++$i) {
            $this->postJson('/api/auth/login', ['email' => 'owner@shop.test', 'password' => 'wrong-'.$i]);
        }
        $this->postJson('/api/auth/login', ['email' => 'nobody@shop.test', 'password' => 'wrong']);

        $tester = $this->signIns((string) self::heldNow()->modify('-1 hour')->getTimestamp());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('failed=7 locked=1', trim($tester->getDisplay()));
    }

    public function testAMomentThatIsNoneIsRefused(): void
    {
        foreach (['', 'yesterday', '-5', '1.5'] as $since) {
            $tester = $this->signIns($since);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $since.': '.$tester->getDisplay());
        }
    }

    private function signIns(string $since): ApplicationTester
    {
        $this->em()->clear();
        $application = new Application(self::$kernel ?? self::fail('No kernel.'));
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'app:installation:sign-ins', '--since' => $since, '--no-interaction' => true]);

        return $tester;
    }
}
