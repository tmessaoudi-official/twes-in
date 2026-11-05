<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The way back from a computer clock that ran ahead (docs/SPEC.md § 7, Q7): an operator lets every series of one company
 * take a day before its last number's for a few hours, said in the company's trail with why and what the clock read, and
 * nothing is given beyond the operator's own limit. Given too long or to the wrong company, it is ended early, said too.
 */
final class AllowEarlierNumberingDaysCommandTest extends ApiTestCase
{
    private const string REASON = 'Horloge du poste avancée au 12';

    public function testAnOperatorLetsEverySeriesOfACompanyTakeAnEarlierDayForAWhileSaidInItsTrail(): void
    {
        $company = $this->provisioned('Quincaillerie');
        $other = $this->provisioned('Ailleurs');

        $tester = $this->run_($company->getId()->toRfc4122(), '3', ['--reason' => "  \u{200B}".self::REASON.' ']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('until', $tester->getDisplay());
        $connection = $this->em()->getConnection();
        $allowed = Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND is_default AND earlier_day_allowed_until > now()', [$company->getId()->toRfc4122()]));
        $defaults = $this->defaults($company);
        self::assertGreaterThan(0, $defaults);
        self::assertSame($defaults, $allowed, 'every series that numbers');
        self::assertSame(0, Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$other->getId()->toRfc4122()])), 'no other company');
        $trail = $this->trail($company, 'numbering_series.earlier_day_allowed');
        self::assertCount($defaults, $trail, 'one trail line per series');
        foreach ($trail as $changes) {
            // jsonb keeps its keys in an order of its own.
            self::assertEqualsCanonicalizing(['earlier_day_allowed_until', 'reason', 'clock_read'], array_keys($changes));
            self::assertSame(self::REASON, $changes['reason']);
            self::assertIsString($changes['clock_read']);
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $changes['clock_read']), 'the moment the server\'s clock read when it was given');
        }
    }

    public function testBeyondTheOperatorsLimitAnUnknownCompanyOrNoReasonNothingIsGiven(): void
    {
        $company = $this->provisioned('Quincaillerie');
        $id = $company->getId()->toRfc4122();

        $tooLong = $this->run_($id, '100', ['--reason' => self::REASON]);
        self::assertSame(1, $tooLong->getStatusCode());
        self::assertStringContainsString('at most', $tooLong->getDisplay());
        self::assertSame(1, $this->run_('01a19879-8aaa-73cf-81c7-b975e2a63427', '3', ['--reason' => self::REASON])->getStatusCode());
        foreach (['no reason' => [], 'a blank reason' => ['--reason' => " \u{00A0}\u{200B} "]] as $case => $options) {
            $refused = $this->run_($id, '3', $options);
            self::assertSame(1, $refused->getStatusCode(), $case);
            self::assertStringContainsString('reason', $refused->getDisplay(), $case);
        }
        self::assertSame(0, Rows::int($this->em()->getConnection()->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$id])));
    }

    /** Given too long or to the wrong company, the allowance ends at once, said once per series it was in force on. */
    public function testAnAllowanceEndsEarlySaidInTheTrailAndEndingNoneSaysSo(): void
    {
        $company = $this->provisioned('Quincaillerie');
        $other = $this->provisioned('Ailleurs');
        $id = $company->getId()->toRfc4122();
        self::assertSame(0, $this->run_($id, '72', ['--reason' => self::REASON])->getStatusCode());
        self::assertSame(0, $this->run_($other->getId()->toRfc4122(), '3', ['--reason' => self::REASON])->getStatusCode());

        $noReason = $this->run_($id, null, ['--end' => true]);
        self::assertSame(1, $noReason->getStatusCode(), 'an end says why too');
        $ended = $this->run_($id, null, ['--end' => true, '--reason' => 'Donnée à la mauvaise entreprise']);

        self::assertSame(0, $ended->getStatusCode(), $ended->getDisplay());
        self::assertStringContainsString('ended', $ended->getDisplay());
        $connection = $this->em()->getConnection();
        self::assertSame(0, Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$id])));
        self::assertSame($this->defaults($other), Rows::int($connection->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND earlier_day_allowed_until IS NOT NULL', [$other->getId()->toRfc4122()])), 'no other company\'s');
        $trail = $this->trail($company, 'numbering_series.earlier_day_ended');
        self::assertCount($this->defaults($company), $trail, 'one trail line per series');
        self::assertEqualsCanonicalizing(['reason', 'clock_read'], array_keys($trail[0]));
        self::assertSame('Donnée à la mauvaise entreprise', $trail[0]['reason']);

        $again = $this->run_($id, null, ['--end' => true, '--reason' => 'Encore']);
        self::assertSame(0, $again->getStatusCode());
        self::assertStringContainsString('none was in force', $again->getDisplay());
        self::assertCount($this->defaults($company), $this->trail($company, 'numbering_series.earlier_day_ended'), 'ending none records nothing');
        self::assertSame(1, $this->run_($id, '3', ['--end' => true, '--reason' => 'Les deux'])->getStatusCode(), 'hours or an end, not both');
    }

    private function provisioned(string $name): Company
    {
        $company = $this->createCompany($name);
        static::getContainer()->get(ProvisionCompany::class)->handle($company);

        return $company;
    }

    private function defaults(Company $company): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne('SELECT count(*) FROM numbering_series WHERE company_id = ? AND is_default', [$company->getId()->toRfc4122()]));
    }

    /** @return list<array<array-key, mixed>> what each of the company's trail lines of that action says */
    private function trail(Company $company, string $action): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn('SELECT changes::text FROM audit_log WHERE company_id = ? AND action = ?', [$company->getId()->toRfc4122(), $action]);

        return array_map(static fn (mixed $changes): array => Rows::row(json_decode(\is_string($changes) ? $changes : '', true)), $rows);
    }

    /** @param array<string, mixed> $options */
    private function run_(string $company, ?string $hours, array $options = []): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:numbering:allow-earlier-days'));
        $tester->execute(['company' => $company, ...(null === $hours ? [] : ['hours' => $hours]), ...$options]);

        return $tester;
    }
}
