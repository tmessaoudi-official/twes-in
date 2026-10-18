<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Reports;

use App\Audit\Domain\Model\AuditLog;
use App\Identity\Application\Login\LoginAudit;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\RunReport;
use App\Module\Reports\Infrastructure\Activity\ActivityByMemberReport;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What each person did in the company, read from its journal: every member a row though they did nothing, anyone else
 * who acted too, the days the company's own, and signing in or the account's own security no action in the company.
 */
final class ActivityByMemberReportTest extends KernelTestCase
{
    public function testEachMemberAndAnyoneElseWhoActedIsARowOfWhatTheJournalKept(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $amina = new User(Email::fromString('amina@acme.test'), 'Amina', 'fr');
        $bilel = new User(Email::fromString('bilel@acme.test'), 'Bilel', 'fr');
        $former = new User(Email::fromString('former@acme.test'), 'Ancien', 'fr');
        $role = new Role('tester', ['*'], $acme);
        $theirRole = new Role('tester', ['*'], $globex);
        foreach ([$acme, $globex, $amina, $bilel, $former, $role, $theirRole, new Membership($amina, $acme, $role), new Membership($bilel, $acme, $role), new Membership($amina, $globex, $theirRole)] as $entity) {
            $em->persist($entity);
        }
        $journal = [
            [$amina, LoginAudit::LOGIN, '2026-09-02 08:00', $acme],
            [$amina, LoginAudit::PREFIX.'password_changed', '2026-09-02 08:01', $acme],
            [$amina, LoginAudit::LOGOUT, '2026-09-04 08:00', $acme],
            [$amina, InvoiceWorkflow::ISSUED, '2026-09-02 08:05', $acme],
            // Half past midnight on the 1st in Tunis, still August in UTC: in the period, on the 1st.
            [$amina, ManagePayments::RECORDED, '2026-08-31 23:30', $acme],
            // Half past midnight on the 4th in Tunis, still the 3rd in UTC: the company's day is the 4th.
            [$amina, 'customer.created', '2026-09-03 23:30', $acme],
            // Half past midnight on 1 October in Tunis: past the period.
            [$amina, 'customer.updated', '2026-09-30 23:30', $acme],
            [$amina, InvoiceWorkflow::ISSUED, '2026-09-10 09:00', $globex],
            [$former, 'product.created', '2026-09-10 09:00', $acme],
        ];
        foreach ($journal as [$actor, $action, $moment, $company]) {
            $em->persist(new AuditLog('user', $actor->getId(), $action, $actor->getId(), [], new \DateTimeImmutable($moment, new \DateTimeZone('UTC')), null, $company->getId()));
        }
        $em->flush();
        $em->clear();
        $company = $em->find(Company::class, $acme->getId()) ?? throw new \LogicException('The company is gone.');

        $report = static::getContainer()->get(ActivityByMemberReport::class);
        $result = new RunReport()->run(
            $report,
            new ReportContext($company, new \DateTimeImmutable('2026-10-15'), static fn (string $permission): bool => true),
            new ReportPeriod(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30')),
            ReportComparison::None,
        );

        self::assertSame(['member', 'sign_ins', 'actions', 'days_active', 'last_active', 'issued', 'payments'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([
            ['Amina', 1, 3, 3, '2026-09-04', 1, 1],
            ['Ancien', 0, 1, 1, '2026-09-10', 0, 0],
            ['Bilel', 0, 0, 0, null, 0, 0],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows), 'signing in and out and a password change are no action; Globex\'s invoice is Globex\'s');
        self::assertSame('activity', $result->rows[0]['row']->drill?->list);
        self::assertSame(['actor' => $amina->getId()->toRfc4122(), 'at.from' => '2026-09-01', 'at.to' => '2026-09-30'], $result->rows[0]['row']->drill->filters);
    }
}
