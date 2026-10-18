<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Domain\Model\ExpenseCategory;
use App\Module\Expenses\Domain\Repository\ExpenseRepository;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmounts;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Module\Expenses\Infrastructure\Reports\ExpensesBySupplierReport;
use App\Module\Expenses\Infrastructure\Reports\ExpensesReport;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportResult;
use App\Module\Reports\Application\RunReport;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Expenses module's reports as the database sums the bills: what was booked in the period, paid or not, by category
 * and by whom it was paid to, a payee written in words being one supplier however its case and spaces went.
 */
final class ExpenseReportsTest extends KernelTestCase
{
    private Company $company;
    private Vendor $steg;
    private ExpenseCategory $energy;
    private ExpenseCategory $office;
    private ExpenseCategory $supplies;

    protected function setUp(): void
    {
        self::bootKernel();
        $provision = static::getContainer()->get(ProvisionCompany::class);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        foreach ([$this->company, $globex] as $company) {
            $this->em()->persist($company);
            $this->em()->flush();
            $provision->handle($company);
        }
        $now = new \DateTimeImmutable('2026-01-01');
        $this->steg = Vendor::create($this->company, 'FRN-0001', new VendorProfile('STEG'), $now);
        $this->energy = ExpenseCategory::create($this->company, 'Énergie', null, $now);
        $this->supplies = ExpenseCategory::create($this->company, 'Fournitures', null, $now);
        $this->office = ExpenseCategory::create($this->company, 'Bureau', $this->supplies, $now);
        $theirs = ExpenseCategory::create($globex, 'Énergie', null, $now);
        foreach ([$this->steg, $this->energy, $this->supplies, $this->office, $theirs] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->expense('2026-09-05', '100', $this->energy, $this->steg, tax: 'TVA19');
        $this->expense('2026-09-10', '50', $this->energy, $this->steg, paid: true);
        $this->expense('2026-09-12', '20', $this->office, payee: 'Société  X ');
        $this->expense('2026-09-15', '30', $this->office, payee: 'société x');
        $this->expense('2026-09-20', '10', $this->supplies);
        // Not booked, before the period, another company's: none of them counts.
        $this->expense('2026-09-20', '999', $this->energy, $this->steg, record: false);
        $this->expense('2026-08-31', '77', $this->energy, $this->steg);
        $this->expense('2026-09-05', '500', $theirs, company: $globex);
        $this->em()->clear();
    }

    public function testEachCategorySumsTheBillsBookedInThePeriodNamedUnderItsParent(): void
    {
        $result = $this->report(ExpensesReport::class);

        self::assertSame(['category', 'net', 'taxes', 'gross', 'expenses'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([
            ['Énergie', '150.000', '19.000', '169.000', 2],
            ['Fournitures › Bureau', '50.000', '0.000', '50.000', 2],
            ['Fournitures', '10.000', '0.000', '10.000', 1],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame('expenses', $result->rows[0]['row']->drill?->list);
        self::assertSame(['category' => $this->energy->getId()->toRfc4122(), 'status' => 'recorded,paid', 'date.from' => '2026-09-01', 'date.to' => '2026-09-30'], $result->rows[0]['row']->drill->filters);
        self::assertSame('229.000', $result->totals['gross']);
    }

    public function testEachSupplierIsAVendorOnRecordOrAPayeeHoweverItWasWrittenOrNoneLast(): void
    {
        $result = $this->report(ExpensesBySupplierReport::class);

        self::assertSame(['STEG', '150.000', '19.000', '169.000', 2], array_values($result->rows[0]['row']->cells));
        self::assertSame($this->steg->getId()->toRfc4122(), $result->rows[0]['row']->key);
        self::assertSame(['vendor' => $this->steg->getId()->toRfc4122(), 'status' => 'recorded,paid', 'date.from' => '2026-09-01', 'date.to' => '2026-09-30'], $result->rows[0]['row']->drill?->filters);

        $payee = $result->rows[1]['row'];
        self::assertContains($payee->cells['supplier'], ['Société  X', 'société x'], 'the name as one of its bills wrote it, trimmed');
        self::assertSame(['50.000', '0.000', '50.000', 2], \array_slice(array_values($payee->cells), 1), 'its case and spaces make no second supplier');
        self::assertMatchesRegularExpression('/^payee:[0-9a-f]{32}$/', $payee->key);
        self::assertNull($payee->drill?->list, 'the expenses list narrows to a vendor on record only');
        self::assertSame(['20.000', '30.000'], array_map(static fn (array $document): string => $document[1], $this->documents(ExpensesBySupplierReport::class, $payee->key)));

        $none = $result->rows[2]['row'];
        self::assertSame(['none', null, '10.000'], [$none->key, $none->cells['supplier'], $none->cells['gross']], 'a bill naming nobody comes last');
        self::assertSame([[null, '10.000']], $this->documents(ExpensesBySupplierReport::class, 'none'));
        self::assertCount(3, $result->rows);
        self::assertSame([], $this->documents(ExpensesBySupplierReport::class, 'payee:'.md5('nobody')));
    }

    /** @param class-string<DeclaresReport> $report */
    private function report(string $report): ReportResult
    {
        return new RunReport()->run($this->declared($report), $this->context(), $this->period(), ReportComparison::None);
    }

    /**
     * @param class-string<DeclaresReport> $report
     *
     * @return list<array{?string, string}> each document's party and what it came to
     */
    private function documents(string $report, string $row): array
    {
        return array_map(static fn (ReportDocument $document): array => [$document->party, $document->amount], $this->declared($report)->documents($this->context(), $this->period(), $row));
    }

    /** @param class-string<DeclaresReport> $report */
    private function declared(string $report): DeclaresReport
    {
        $declared = static::getContainer()->get($report);
        self::assertInstanceOf(DeclaresReport::class, $declared);

        return $declared;
    }

    private function period(): ReportPeriod
    {
        return new ReportPeriod(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
    }

    private function context(): ReportContext
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('The company is gone.');

        return new ReportContext($company, new \DateTimeImmutable('2026-09-30'), static fn (string $permission): bool => true);
    }

    private function expense(string $day, string $net, ExpenseCategory $category, ?Vendor $vendor = null, ?string $payee = null, ?string $tax = null, bool $record = true, bool $paid = false, ?Company $company = null): void
    {
        $company ??= $this->company;
        $now = new \DateTimeImmutable('2026-09-30');
        $vat = null === $tax ? null : static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($tax, $company->getId());
        $expense = Expense::create($company, new ExpenseDetails(new \DateTimeImmutable($day), 'Achat', payee: $payee), ExpenseAmounts::one($net, 3, $vat), $vendor, $category, 3, RoundingPoint::PerRateGroup, $now);
        if ($record) {
            $expense->record($now);
        }
        if ($paid) {
            $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable($day), $now, $now);
        }
        static::getContainer()->get(ExpenseRepository::class)->save($expense);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
