<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Shared\Infrastructure\Doctrine\Rows;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The way back from a computer clock that ran ahead (docs/SPEC.md § 7, Q7): an operator lets every series of one company
 * take a day before its last number's for a few hours, said in the company's trail, and nothing is given beyond the
 * operator's own limit.
 */
final class AllowEarlierNumberingDaysCommandTest extends ApiTestCase
{
    public function testAnOperatorLetsEverySeriesOfACompanyTakeAnEarlierDayForAWhileSaidInItsTrail(): void
    {
        $company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $other = $this->createCompany('Ailleurs');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);

        $tester = $this->run_($company->getId()->toRfc4122(), '3');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('until', $tester->getDisplay());
        $connection = $this->em()->getConnection();
        $allowed = Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND is_default AND earlier_day_allowed_until > now()', [$company->getId()->toRfc4122()]));
        $defaults = Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND is_default', [$company->getId()->toRfc4122()]));
        self::assertGreaterThan(0, $defaults);
        self::assertSame($defaults, $allowed, 'every series that numbers');
        self::assertSame(0, Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$other->getId()->toRfc4122()])), 'no other company');
        self::assertSame($defaults, Rows::int($connection->fetchOne("SELECT count(*) FROM audit_log WHERE company_id = ? AND action = 'numbering_series.earlier_day_allowed'", [$company->getId()->toRfc4122()])), 'one trail line per series');
    }

    public function testBeyondTheOperatorsLimitOrAnUnknownCompanyNothingIsGiven(): void
    {
        $company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($company);

        $tooLong = $this->run_($company->getId()->toRfc4122(), '100');
        self::assertSame(1, $tooLong->getStatusCode());
        self::assertStringContainsString('at most', $tooLong->getDisplay());
        self::assertSame(1, $this->run_('01a19879-8aaa-73cf-81c7-b975e2a63427', '3')->getStatusCode());
        self::assertSame(0, Rows::int($this->em()->getConnection()->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$company->getId()->toRfc4122()])));
    }

    private function run_(string $company, string $hours): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:numbering:allow-earlier-days'));
        $tester->execute(['company' => $company, 'hours' => $hours]);

        return $tester;
    }
}
