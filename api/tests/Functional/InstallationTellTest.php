<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKinds;
use App\Tenancy\Domain\Model\Role;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * What a shop's installation script (scripts/onprem/twes) tells the application about itself: a release out goes to
 * each company's owners, who decide when it is applied; what went wrong goes to the platform's operators, who mend it.
 * The script mails its own alerts too, since the application may be the one down; here they reach the bell.
 */
final class InstallationTellTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    public function testAReleaseOutIsToldToEachCompanysOwnersAndToNobodyElse(): void
    {
        $shop = $this->createCompany('Quincaillerie');
        $other = $this->createCompany('Atelier');
        $owner = $this->createUser('owner@shop.test', self::PASSWORD, $shop);
        $this->createUser('clerk@shop.test', self::PASSWORD, $shop, ['company.read'], Role::MEMBER);
        $second = $this->createUser('owner@atelier.test', self::PASSWORD, $other);
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);

        $tester = $this->tell('installation.update_available', 'version=v2026.10.21.1', 'previous=v2026.10.20.1');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $told = $this->told('installation.update_available');
        self::assertEqualsCanonicalizing([
            [$owner->getId()->toRfc4122(), $shop->getId()->toRfc4122()],
            [$second->getId()->toRfc4122(), $other->getId()->toRfc4122()],
        ], array_map(static fn (array $row): array => [$row['recipient_id'], $row['company_id']], $told));
        self::assertSame(['version' => 'v2026.10.21.1', 'previous' => 'v2026.10.20.1'], json_decode($this->stringAt($told[0], 'payload'), true));
    }

    public function testWhatWentWrongIsToldToTheOperatorsAndToNoCompany(): void
    {
        $shop = $this->createCompany('Quincaillerie');
        $this->createUser('owner@shop.test', self::PASSWORD, $shop);
        $operator = $this->createUser('operator@twes.local', self::PASSWORD, operator: true);

        $cases = [
            ['installation.rolled_back', ['version=v2026.10.21.1', 'previous=v2026.10.20.1', 'label=20261021-090000F_20261021-093000D']],
            ['installation.update_failed', ['version=v2026.10.21.1', 'previous=-', 'label=-']],
            ['installation.unhealthy', ['check=database,worker']],
            ['installation.unhealthy', ['check=api (502)']],
            ['installation.backup_failed', ['step=copy']],
            ['installation.backup_failed', ['version=v2026.10.21.1', 'step=before-update']],
            ['installation.backup_stopped', ['step=due']],
            ['installation.update_refused', ['version=v2026.10.22.1']],
        ];
        foreach ($cases as [$kind, $parameters]) {
            $tester = $this->tell($kind, ...$parameters);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $kind.': '.$tester->getDisplay());
        }

        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT recipient_id, company_id, type, payload FROM inbox_item WHERE type LIKE 'installation.%' ORDER BY type");
        self::assertCount(\count($cases), $rows);
        foreach ($rows as $row) {
            self::assertSame($operator->getId()->toRfc4122(), $row['recipient_id']);
            self::assertNull($row['company_id']);
        }
        self::assertContains(['check' => 'api (502)'], array_map(fn (array $row): mixed => json_decode($this->stringAt($row, 'payload'), true), $rows));
    }

    /** A line the script could not have meant is refused whole, so nothing half-said reaches anyone. */
    public function testAnUnknownKindOrAParameterItDoesNotTakeIsRefusedAndTellsNobody(): void
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);

        foreach ([
            ['installation.reboot'],
            ['data.check_failed', 'check=x'],
            ['installation.unhealthy'],
            ['installation.unhealthy', 'check=database', 'version=v1'],
            ['installation.unhealthy', 'check'],
            ['installation.unhealthy', 'check='],
            ['installation.unhealthy', "check=database\nworker"],
            ['installation.unhealthy', 'check=<b>database</b>'],
            ['installation.unhealthy', 'check='.str_repeat('a', 121)],
            ['installation.backup_stopped', 'step=due', 'step=copy'],
            ['installation.update_refused'],
        ] as $line) {
            $tester = $this->tell(...$line);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), implode(' ', $line).' '.$tester->getDisplay());
        }
        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn("SELECT type FROM inbox_item WHERE type LIKE 'installation.%' OR type = 'data.check_failed'"));
    }

    /** The bell's preferences offer each kind to whom it is told; none is mailed here, the script mails on its own. */
    public function testTheKindsAreDeclaredForTheirAudienceAndMailedByTheScriptAlone(): void
    {
        $kinds = static::getContainer()->get(NotificationKinds::class);
        $release = $kinds->get('installation.update_available');
        self::assertNotNull($release);
        self::assertSame(NotificationAudience::Company, $release->audience);
        self::assertSame(Role::OWNER, $release->role);
        self::assertFalse($release->mailed);
        foreach (['rolled_back', 'update_failed', 'update_refused', 'unhealthy', 'backup_failed', 'backup_stopped'] as $name) {
            $kind = $kinds->get('installation.'.$name);
            self::assertNotNull($kind, $name);
            self::assertSame(NotificationAudience::Platform, $kind->audience, $name);
            self::assertFalse($kind->mailed, $name);
        }
    }

    private function tell(string ...$line): ApplicationTester
    {
        $this->em()->clear();
        $application = new Application(self::$kernel ?? self::fail('No kernel.'));
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);
        $kind = array_shift($line);
        $tester->run(['command' => 'app:installation:tell', 'kind' => $kind, 'parameters' => $line, '--no-interaction' => true]);

        return $tester;
    }

    /** @return list<array<string, mixed>> */
    private function told(string $type): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT recipient_id, company_id, payload FROM inbox_item WHERE type = ?', [$type]);
    }
}
