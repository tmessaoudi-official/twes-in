<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Reports\Application;

use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\InvalidReportRequest;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use App\Module\Reports\Application\RunReport;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\TestCase;

final class RunReportTest extends TestCase
{
    private ReportContext $context;
    private ReportPeriod $october;

    protected function setUp(): void
    {
        $this->context = new ReportContext(new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), new \DateTimeImmutable('2026-10-18'), static fn (string $permission): bool => true);
        $this->october = new ReportPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }

    public function testEachRowIsReadBesideTheSameRowOfThePeriodCompared(): void
    {
        $report = new SalesByCustomerStub([
            '2026-10-01' => [self::row('c1', 'Sousse Bois', '100.500', 2), self::row('c2', 'Bizerte Fer', '40.000', 1)],
            '2026-09-01' => [self::row('c2', 'Bizerte Fer', '10.250', 1)],
        ]);

        $result = new RunReport()->run($report, $this->context, $this->october, ReportComparison::Previous);

        self::assertSame(['2026-09-01', '2026-09-30'], [$result->comparison?->from->format('Y-m-d'), $result->comparison?->to->format('Y-m-d')]);
        self::assertSame(['c1', 'c2'], array_map(static fn (array $line): string => $line['row']->key, $result->rows));
        self::assertNull($result->rows[0]['previous']);
        self::assertSame(['customer' => 'Bizerte Fer', 'net' => '10.250', 'invoices' => 1], $result->rows[1]['previous']);
    }

    public function testARowOnlyThePeriodComparedHadIsKeptWithNothingCounted(): void
    {
        $report = new SalesByCustomerStub([
            '2026-10-01' => [self::row('c1', 'Sousse Bois', '100.500', 2)],
            '2026-09-01' => [self::row('c3', 'Gabès Bricolage', '75.000', 3)],
        ]);

        $result = new RunReport()->run($report, $this->context, $this->october, ReportComparison::Previous);

        self::assertSame('c3', $result->rows[1]['row']->key);
        self::assertSame(['customer' => 'Gabès Bricolage', 'net' => null, 'invoices' => null], $result->rows[1]['row']->cells);
        self::assertSame(['customer' => 'Gabès Bricolage', 'net' => '75.000', 'invoices' => 3], $result->rows[1]['previous']);
        self::assertNotNull($result->rows[1]['row']->drill);
    }

    public function testTotalsAddUpWhatTheRowsHoldAtTheirWidestScale(): void
    {
        $report = new SalesByCustomerStub([
            '2026-10-01' => [self::row('c1', 'Sousse Bois', '100.5', 2), self::row('c2', 'Bizerte Fer', '-40.125', 1), self::row('c3', 'Gabès', null, 0)],
            '2026-09-01' => [self::row('c2', 'Bizerte Fer', '0.10', 4)],
        ]);

        $result = new RunReport()->run($report, $this->context, $this->october, ReportComparison::Previous);

        self::assertSame(['net' => '60.375', 'invoices' => 3], $result->totals);
        self::assertSame(['net' => '0.10', 'invoices' => 4], $result->previousTotals);
    }

    public function testQuantitiesAndDaysAreNeverAddedUpAndAreNotCountedOnARowOnlyThePeriodComparedHad(): void
    {
        $sold = static fn (string $key, string $quantity): ReportRow => new ReportRow($key, ['customer' => $key, 'net' => '1.000', 'invoices' => 1, 'quantity' => $quantity, 'quiet' => 40]);
        $report = new SalesByCustomerStub([
            '2026-10-01' => [$sold('pieces', '3'), $sold('metres', '2.500')],
            '2026-09-01' => [$sold('litres', '7.000')],
        ], quantities: true);

        $result = new RunReport()->run($report, $this->context, $this->october, ReportComparison::Previous);

        self::assertSame(['net' => '2.000', 'invoices' => 2], $result->totals, 'three pieces and two and a half metres make no five and a half of anything, nor two quiet spells of 40 days one of 80');
        self::assertSame(['customer' => 'litres', 'net' => null, 'invoices' => null, 'quantity' => null, 'quiet' => null], $result->rows[2]['row']->cells);
    }

    public function testWithoutAComparisonNothingIsReadTwice(): void
    {
        $report = new SalesByCustomerStub(['2026-10-01' => [self::row('c1', 'Sousse Bois', '1.000', 1)]]);

        $result = new RunReport()->run($report, $this->context, $this->october, ReportComparison::None);

        self::assertSame(['2026-10-01'], $report->read);
        self::assertNull($result->comparison);
        self::assertNull($result->previousTotals);
        self::assertNull($result->rows[0]['previous']);
    }

    public function testAReportThatCannotBeNarrowedToAnEstablishmentRefusesOne(): void
    {
        $report = new SalesByCustomerStub([], byEstablishment: false);
        $narrowed = new ReportContext($this->context->company, $this->context->today, $this->context->may, 'an-establishment');

        $this->expectException(InvalidReportRequest::class);
        new RunReport()->run($report, $narrowed, $this->october, ReportComparison::None);
    }

    public function testAReportOfTodayRefusesAComparison(): void
    {
        $report = new SalesByCustomerStub([], periodic: false);

        $this->expectException(InvalidReportRequest::class);
        new RunReport()->run($report, $this->context, $this->october, ReportComparison::Previous);
    }

    public function testAReportGivingOneRowTwiceIsAFault(): void
    {
        $report = new SalesByCustomerStub(['2026-10-01' => [self::row('c1', 'A', '1', 1), self::row('c1', 'A', '2', 1)]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('gives the row c1 twice');
        new RunReport()->run($report, $this->context, $this->october, ReportComparison::None);
    }

    private static function row(string $key, string $customer, ?string $net, int $invoices): ReportRow
    {
        return new ReportRow($key, ['customer' => $customer, 'net' => $net, 'invoices' => $invoices], new ReportDrill('invoices', ['customerId[]' => $key]));
    }
}

final class SalesByCustomerStub implements DeclaresReport
{
    /** @var list<string> the first day of every period read */
    public array $read = [];

    /** @param array<string, list<ReportRow>> $rows by the first day of the period */
    public function __construct(private readonly array $rows, private readonly bool $byEstablishment = true, private readonly bool $periodic = true, private readonly bool $quantities = false)
    {
    }

    public function key(): string
    {
        return 'sales-by-customer';
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return 'invoice.read';
    }

    public function byEstablishment(): bool
    {
        return $this->byEstablishment;
    }

    public function periodic(): bool
    {
        return $this->periodic;
    }

    public function columns(ReportContext $context): array
    {
        $columns = [new ReportColumn('customer', ReportColumnKind::Label), new ReportColumn('net', ReportColumnKind::Amount), new ReportColumn('invoices', ReportColumnKind::Count)];

        return $this->quantities ? [...$columns, new ReportColumn('quantity', ReportColumnKind::Quantity), new ReportColumn('quiet', ReportColumnKind::Days)] : $columns;
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $this->read[] = $period->from->format('Y-m-d');

        return $this->rows[$period->from->format('Y-m-d')] ?? [];
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        return [];
    }
}
