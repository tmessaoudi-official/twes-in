<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Invoices\Application\InvoiceSummarySource;
use App\Module\Invoices\Application\SummarizeInvoices;
use App\Module\Invoices\Domain\Model\CustomerCreditEntry;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\Payment;
use App\Module\Invoices\Domain\Repository\CustomerCreditRepository;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\InvoiceFigures;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\InvoiceIssue;
use App\Module\Invoices\Domain\ValueObject\InvoiceLineDetails;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Module\Invoices\Infrastructure\Reports\AgedBalanceReport;
use App\Module\Invoices\Infrastructure\Reports\CreditNotesAndDiscountsReport;
use App\Module\Invoices\Infrastructure\Reports\SalesByCustomerReport;
use App\Module\Invoices\Infrastructure\Reports\SalesByFamilyReport;
use App\Module\Invoices\Infrastructure\Reports\SalesByProductReport;
use App\Module\Invoices\Infrastructure\Reports\TakingsReport;
use App\Module\Invoices\Infrastructure\Reports\VatByRateReport;
use App\Module\Invoices\Infrastructure\Reports\WithholdingSufferedReport;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportResult;
use App\Module\Reports\Application\RunReport;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tests\Support\KeptNames;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The Invoices module's reports as the database sums them, each checked against the figure the home shows for the same
 * days where the home has one: a report that disagrees with the home is a report nobody can trust.
 */
final class InvoiceReportsTest extends KernelTestCase
{
    private MockClock $clock;
    private Company $company;
    private Company $globex;
    /** @var array<string, Customer> */
    private array $customers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        // Half past eleven at night in UTC is already the 21st in Tunis.
        $this->clock = new MockClock('2026-09-20 23:30:00', 'UTC');
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $provision = static::getContainer()->get(ProvisionCompany::class);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        foreach ([$this->company, $this->globex] as $company) {
            $this->em()->persist($company);
            $this->em()->flush();
            $provision->handle($company);
        }
    }

    public function testTheTakingsOfEachDayAddUpToWhatTheHomeCountsAsCollected(): void
    {
        $paid = $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        $this->pay($paid, '2026-08-20', '400');
        $this->pay($paid, '2026-09-05', '600', PaymentMethod::Cash);
        $soon = $this->issued('FAC-S', 'Sousse Deux', '2026-09-02', 30, '500.000');
        $this->pay($soon, '2026-09-02', '200', PaymentMethod::Check);
        $this->pay($soon, '2026-09-02', '25.500', PaymentMethod::Card);
        // Credit sent to the balance in August and applied in September was money taken in August: it is not taken twice.
        $first = $this->issued('FAC-A', 'Nabeul Bois', '2026-08-05', 0, '100.000');
        $this->pay($first, '2026-08-10', '100');
        $customer = $first->getCustomer();
        $credits = static::getContainer()->get(CustomerCreditRepository::class);
        $credits->save(CustomerCreditEntry::credited($customer, $first, 'AV-A', '100.000', new \DateTimeImmutable('2026-08-15'), null, $this->clock->now()));
        $second = $this->issued('FAC-B', 'Nabeul Bois', '2026-09-03', 0, '100.000');
        $applied = $this->pay($second, '2026-09-04', '100', PaymentMethod::Other);
        $credits->save(CustomerCreditEntry::applied($customer, $applied, null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::overpayment($second, new PaymentDetails(new \DateTimeImmutable('2026-09-06'), '50.000', PaymentMethod::Cash), null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::credited($customer, $second, 'AV-B', '30.000', new \DateTimeImmutable('2026-09-08'), null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::refunded($customer, $second, 'AV-B', '30.000', new \DateTimeImmutable('2026-09-08'), null, $this->clock->now()));
        $theirs = $this->issued('GLX-1', 'Autre', '2026-09-03', 0, '1000.000', company: $this->globex);
        $this->pay($theirs, '2026-09-05', '999', PaymentMethod::Cash);
        $this->em()->clear();

        $result = $this->report(TakingsReport::class, '2026-09-01', '2026-09-21');

        self::assertCount(21, $result->rows, 'every day of the period, a day without takings included');
        self::assertSame([
            'd1' => ['day' => '2026-09-02', 'transfer' => null, 'cash' => null, 'check' => '200.000', 'card' => '25.500', 'other' => null, 'overpaid' => null, 'refunded' => null, 'total' => '225.500'],
            'd4' => ['day' => '2026-09-05', 'transfer' => null, 'cash' => '600.000', 'check' => null, 'card' => null, 'other' => null, 'overpaid' => null, 'refunded' => null, 'total' => '600.000'],
            'd5' => ['day' => '2026-09-06', 'transfer' => null, 'cash' => null, 'check' => null, 'card' => null, 'other' => null, 'overpaid' => '50.000', 'refunded' => null, 'total' => '50.000'],
            'd7' => ['day' => '2026-09-08', 'transfer' => null, 'cash' => null, 'check' => null, 'card' => null, 'other' => null, 'overpaid' => null, 'refunded' => '-30.000', 'total' => '-30.000'],
        ], $this->rowsWith($result, 'total'));
        self::assertNull($result->rows[0]['row']->drill, 'a day without takings has no documents to open');
        $summary = $this->summary();
        self::assertSame(['845.500', '845.500'], [$result->totals['total'], $summary->collectedMonth]);

        // The documents of one day add up to that day's figure, each with what it brought.
        self::assertSame([['FAC-S', '25.500'], ['FAC-S', '200.000']], $this->documents(TakingsReport::class, '2026-09-01', '2026-09-21', 'd1'));
        self::assertSame([], $this->documents(TakingsReport::class, '2026-09-01', '2026-09-21', 'd21'), 'a place past the period');
        self::assertSame([], $this->documents(TakingsReport::class, '2026-09-01', '2026-09-21', 'x1'));
    }

    public function testWhatEachCustomerOwesTodayByHowLateAddsUpToTheHomesOutstanding(): void
    {
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000');
        $soon = $this->issued('FAC-S', 'Sousse Print', '2026-08-25', 30, '500.000');
        $this->pay($soon, '2026-09-02', '200');
        $this->issued('FAC-O1', 'Pharmacie Ennasr', '2026-08-12', 30, '850.050');
        $this->issued('FAC-O2', 'Garage Ben Arous', '2026-07-11', 30, '300.000');
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1190.000');
        $this->pay($old, '2026-07-15', '190');
        $this->credit($old, 'AV-1', '2026-09-10', '-119.000');
        $paid = $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        $this->pay($paid, '2026-09-05', '1000');
        $this->issued('GLX-1', 'Autre', '2026-05-01', 0, '9999.000', company: $this->globex);
        $this->em()->clear();

        $result = $this->report(AgedBalanceReport::class);

        self::assertSame([
            ['Nabeul Bois', '1200.000', null, null, null, null, '1200.000', 1],
            ['Transports Sahel', null, null, null, '881.000', null, '881.000', 1],
            ['Pharmacie Ennasr', null, '850.050', null, null, null, '850.050', 1],
            ['Garage Ben Arous', null, null, '300.000', null, null, '300.000', 1],
            ['Sousse Print', '300.000', null, null, null, null, '300.000', 1],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame(['3531.050', $this->summary()->outstanding], [$result->totals['total'], '3531.050']);
        // The invoices list's own address, which lists what makes up the customer's total.
        $nabeul = $this->customers['Nabeul Bois']->getId()->toRfc4122();
        self::assertSame('invoices', $result->rows[0]['row']->drill?->list);
        self::assertSame(['customer' => $nabeul, 'status' => 'issued,partially_paid', 'type' => 'invoice,deposit', 'amountDue.min' => '0.001'], $result->rows[0]['row']->drill->filters);

        // Narrowed to an establishment, which the list cannot narrow by, a row opens its own documents.
        $narrowed = $this->report(AgedBalanceReport::class, establishment: $this->establishment($this->company));
        self::assertNotNull($narrowed->rows[0]['row']->drill);
        self::assertNull($narrowed->rows[0]['row']->drill->list);
        $sahel = $this->customers['Transports Sahel']->getId()->toRfc4122();
        self::assertSame([['FAC-O3', '881.000']], $this->documents(AgedBalanceReport::class, null, null, $sahel, $this->establishment($this->company)));
        self::assertSame([], $this->documents(AgedBalanceReport::class, null, null, 'not-a-customer'));
    }

    public function testADayLateIsInTheFirstBracketAndEachBracketEndsOnItsOwnDay(): void
    {
        // Due today, 30, 31, 60, 61, 90 and 91 days ago.
        foreach (['2026-09-21', '2026-08-22', '2026-08-21', '2026-07-23', '2026-07-22', '2026-06-23', '2026-06-22'] as $n => $due) {
            $this->issued('FAC-'.$n, 'Client '.$n, $due, 0, '1.000');
        }
        $this->em()->clear();

        $result = $this->report(AgedBalanceReport::class);

        $brackets = [];
        foreach ($result->rows as $line) {
            $cells = array_filter(\array_slice($line['row']->cells, 1, 5, true), static fn (mixed $cell): bool => null !== $cell);
            $brackets[(string) $line['row']->cells['customer']] = array_key_first($cells);
        }
        ksort($brackets);
        self::assertSame([
            'Client 0' => 'not_due', 'Client 1' => 'late_1', 'Client 2' => 'late_2', 'Client 3' => 'late_2',
            'Client 4' => 'late_3', 'Client 5' => 'late_3', 'Client 6' => 'late_last',
        ], $brackets);
        self::assertSame([[], ['from' => 1, 'to' => 30], ['from' => 31, 'to' => 60], ['from' => 61, 'to' => 90], ['after' => 90]], array_map(static fn ($column): array => $column->params, \array_slice($result->columns, 1, 5)));
    }

    public function testTheBracketsAreTheCompanysOwnDaysReadInOrderAndTwoEqualBoundsMakeOne(): void
    {
        // Due 15, 16, 45 and 46 days ago.
        foreach (['2026-09-06', '2026-09-05', '2026-08-07', '2026-08-06'] as $n => $due) {
            $this->issued('FAC-'.$n, 'Client '.$n, $due, 0, '1.000');
        }
        $now = new \DateTimeImmutable();
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('The company is gone.');
        foreach (['aged_balance.first_days' => 45, 'aged_balance.second_days' => 15, 'aged_balance.third_days' => 45] as $key => $days) {
            $this->em()->persist(new Setting(SettingAddress::company($company), $key, $days, $now));
        }
        $this->em()->flush();
        $this->em()->clear();

        $result = $this->report(AgedBalanceReport::class);

        self::assertSame(['customer', 'not_due', 'late_1', 'late_2', 'late_last', 'total', 'invoices'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([['from' => 1, 'to' => 15], ['from' => 16, 'to' => 45], ['after' => 45]], array_map(static fn ($column): array => $column->params, \array_slice($result->columns, 2, 3)));
        $brackets = [];
        foreach ($result->rows as $line) {
            $cells = array_filter(\array_slice($line['row']->cells, 1, 4, true), static fn (mixed $cell): bool => null !== $cell);
            $brackets[(string) $line['row']->cells['customer']] = array_key_first($cells);
        }
        ksort($brackets);
        self::assertSame(['Client 0' => 'late_1', 'Client 1' => 'late_2', 'Client 2' => 'late_2', 'Client 3' => 'late_last'], $brackets);
    }

    public function testWhatEachCustomerWasInvoicedAddsUpToTheHomeAndWhoBoughtLastMonthOnlyComesLast(): void
    {
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000');
        $this->issued('FAC-X', 'Sans coût', '2026-09-16', 30, '300.000');
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1190.000');
        $this->credit($old, 'AV-1', '2026-09-10', '-119.000');
        $this->issued('FAC-O1', 'Pharmacie Ennasr', '2026-08-12', 30, '850.050');
        $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        // Past the days compared, and a draft, and another company's: none counts.
        $this->issued('FAC-S', 'Sousse Print', '2026-08-25', 30, '500.000');
        $this->issued('FAC-S2', 'Nabeul Bois', '2026-09-02', 30, '500.000');
        $this->draft('Brouillon');
        $this->issued('GLX-1', 'Autre', '2026-09-03', 0, '9999.000', company: $this->globex);
        $this->em()->clear();

        $result = $this->report(SalesByCustomerReport::class, '2026-09-01', '2026-09-21', ReportComparison::Previous);

        self::assertSame([
            ['Nabeul Bois', '1700.000', 2, null],
            ['Sans coût', '300.000', 1, null],
            ['Transports Sahel', '-119.000', 1, null],
            ['Hôtel Dar Zarrouk', null, null, '1000.000'],
            ['Pharmacie Ennasr', null, null, '850.050'],
        ], array_map(static fn (array $line): array => [$line['row']->cells['customer'], $line['row']->cells['net'], $line['row']->cells['documents'], $line['previous']['net'] ?? null], $result->rows));
        $summary = $this->summary();
        self::assertSame([$summary->invoicedMonth, $summary->invoicedLastMonth], [$result->totals['net'], $result->previousTotals['net'] ?? null]);
        $nabeul = $this->customers['Nabeul Bois']->getId()->toRfc4122();
        self::assertSame(['customer' => $nabeul, 'status' => 'issued,partially_paid,paid', 'type' => 'invoice,deposit,credit_note', 'issueDate.from' => '2026-09-01', 'issueDate.to' => '2026-09-21'], $result->rows[0]['row']->drill?->filters);
        self::assertSame([['FAC-S2', '500.000'], ['FAC-N', '1200.000']], $this->documents(SalesByCustomerReport::class, '2026-09-01', '2026-09-21', $nabeul));
    }

    public function testEachProductSoldIsItsShareOfTheDocumentsNetAndItsMarginIsShownOnlyToWhoMayReadCosts(): void
    {
        $piece = $this->unit('C62');
        $drill = $this->product('PER-08', 'Perceuse', $piece);
        $vis = $this->product('VIS-04', 'Vis', $piece);
        // Three lines: two products and a line naming none, each at its own net.
        $first = $this->sold('FAC-1', '2026-09-10', [[$drill, '2'], [$vis, '10'], [null, '1']], ['600.000', '300.000', '100.000'], '1000.000');
        $this->costed($first, 1, '200');
        // Prices typed without tax: the line keeps 500 before the document's discount of 50, its share is 450.
        $second = $this->sold('FAC-2', '2026-09-12', [[$drill, '1']], ['500.000'], '450.000');
        $this->costed($second, 1, '200');
        // Another company's sale of the same reference counts nowhere.
        $this->sold('GLX-1', '2026-09-12', [[$this->product('PER-08', 'Perceuse', $this->unit('C62', $this->globex), $this->globex), '9']], ['9000.000'], '9000.000', $this->globex);
        $this->em()->clear();

        $result = $this->report(SalesByProductReport::class, '2026-09-01', '2026-09-21');

        self::assertSame(['product', 'reference', 'unit', 'quantity', 'net', 'costed_net', 'cost', 'margin'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([
            ['Perceuse', 'PER-08', 'C62', '3.000', '1050.000', '1050.000', '600.000', '450.000'],
            ['Vis', 'VIS-04', 'C62', '10.000', '300.000', null, null, null],
            [null, null, null, null, '100.000', null, null, null],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame('none', $result->rows[2]['row']->key);
        self::assertSame(['1450.000', $this->summary()->invoicedMonth], [$result->totals['net'], '1450.000']);
        self::assertSame([['FAC-1', '600.000'], ['FAC-2', '450.000']], $this->documents(SalesByProductReport::class, '2026-09-01', '2026-09-21', $result->rows[0]['row']->key));
        self::assertSame([['FAC-1', '100.000']], $this->documents(SalesByProductReport::class, '2026-09-01', '2026-09-21', 'none'));

        $withoutCosts = $this->report(SalesByProductReport::class, '2026-09-01', '2026-09-21', may: false);
        self::assertSame(['product', 'reference', 'unit', 'quantity', 'net'], array_map(static fn ($column): string => $column->key, $withoutCosts->columns));
        self::assertArrayNotHasKey('cost', $withoutCosts->rows[0]['row']->cells);
        self::assertArrayNotHasKey('margin', $withoutCosts->totals);
    }

    public function testEachFamilyIsItsProductsSalesNamedByItsPathAndTheLinesWithoutOneAreOneRow(): void
    {
        $piece = $this->unit('C62');
        $tools = $this->category('Outillage');
        $drills = $this->category('Perceuses', $tools);
        $drill = $this->product('PER-08', 'Perceuse', $piece, category: $drills);
        $hammer = $this->product('MAR-01', 'Marteau', $piece, category: $tools);
        $loose = $this->product('VIS-04', 'Vis', $piece);
        $first = $this->sold('FAC-1', '2026-09-10', [[$drill, '2'], [$hammer, '1'], [$loose, '10'], [null, '1']], ['600.000', '150.000', '300.000', '100.000'], '1150.000');
        $this->costed($first, 1, '200');
        $returned = $this->sold('FAC-2', '2026-09-12', [[$drill, '1']], ['300.000'], '300.000');
        $this->credit($returned, 'AV-1', '2026-09-15', '-300.000');
        $this->sold('GLX-1', '2026-09-12', [[$this->product('PER-08', 'Perceuse', $this->unit('C62', $this->globex), $this->globex), '9']], ['9000.000'], '9000.000', $this->globex);
        $this->em()->clear();

        $result = $this->report(SalesByFamilyReport::class, '2026-09-01', '2026-09-21');

        self::assertSame(['family', 'net', 'costed_net', 'cost', 'margin'], array_map(static fn ($column): string => $column->key, $result->columns));
        // The credit note copies its invoice's lines, so it takes the drill back from its own family; the screws, which have
        // no family, sit with the line naming no product.
        self::assertSame([
            ['Outillage › Perceuses', '600.000', '600.000', '400.000', '200.000'],
            ['Outillage', '150.000', null, null, null],
            [null, '400.000', null, null, null],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame($drills->getId()->toRfc4122(), $result->rows[0]['row']->key);
        self::assertSame('none', $result->rows[2]['row']->key);
        self::assertSame(['1150.000', $this->summary()->invoicedMonth], [$result->totals['net'], '1150.000']);
        self::assertSame([['FAC-1', '600.000'], ['FAC-2', '300.000'], ['AV-1', '-300.000']], $this->documents(SalesByFamilyReport::class, '2026-09-01', '2026-09-21', $result->rows[0]['row']->key));
        self::assertSame([], $this->documents(SalesByFamilyReport::class, '2026-09-01', '2026-09-21', 'not-a-family'));
        self::assertSame(['family', 'net'], array_map(static fn ($column): string => $column->key, $this->report(SalesByFamilyReport::class, '2026-09-01', '2026-09-21', may: false)->columns));
    }

    public function testWhatEachCustomerWasGivenBackIsItsCreditNotesAndItsInvoicesDiscountsAsIssuingKeptThem(): void
    {
        $discounted = $this->issued('FAC-1', 'Nabeul Bois', '2026-09-05', 30, '450.000');
        $this->saved($discounted, '50.000');
        $credited = $this->issued('FAC-2', 'Nabeul Bois', '2026-09-06', 30, '1190.000');
        $this->credit($credited, 'AV-1', '2026-09-10', '-119.000');
        $other = $this->issued('FAC-3', 'Sousse Print', '2026-09-07', 30, '200.000');
        $this->saved($other, '20.000');
        // No discount kept, a credit note before the period, a discount before the period, another company's: none counts.
        $this->issued('FAC-4', 'Pharmacie Ennasr', '2026-09-08', 30, '300.000');
        $this->credit($this->issued('FAC-5', 'Pharmacie Ennasr', '2026-08-01', 30, '100.000'), 'AV-2', '2026-08-20', '-100.000');
        $this->saved($this->issued('FAC-6', 'Garage Ben Arous', '2026-08-15', 30, '100.000'), '10.000');
        $this->saved($this->issued('GLX-1', 'Autre', '2026-09-05', 0, '900.000', company: $this->globex), '99.000');
        $this->em()->clear();

        $result = $this->report(CreditNotesAndDiscountsReport::class, '2026-09-01', '2026-09-21');

        self::assertSame([
            ['Nabeul Bois', '119.000', 1, '50.000', 1],
            ['Sousse Print', null, 0, '20.000', 1],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame(['119.000', 1, '70.000', 2], [$result->totals['credited'], $result->totals['credit_notes'], $result->totals['discounted'], $result->totals['discounted_invoices']]);
        $nabeul = $result->rows[0]['row']->key;
        self::assertSame([['FAC-1', '50.000'], ['AV-1', '119.000']], $this->documents(CreditNotesAndDiscountsReport::class, '2026-09-01', '2026-09-21', $nabeul));
        self::assertSame([], $this->documents(CreditNotesAndDiscountsReport::class, '2026-09-01', '2026-09-21', 'not-a-customer'));
    }

    public function testTheVatCollectedByRateIsWhatTheDocumentsFrozeAndAddsUpToTheHome(): void
    {
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1000.000', taxes: ['TVA19' => ['1000.000', '190.000'], 'FODEC' => ['1000.000', '10.000']]);
        $this->issued('FAC-M', 'Mahdia Plast', '2026-09-16', 30, '200.000', taxes: ['TVA19' => ['200.000', '38.000']]);
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1000.000', taxes: ['TVA19' => ['1000.000', '190.000']]);
        $this->credit($old, 'AV-1', '2026-09-10', '-100.000', ['TVA19' => ['-100.000', '-19.000']]);
        $this->issued('GLX-1', 'Autre', '2026-09-03', 0, '1000.000', $this->globex, ['TVA19' => ['1000.000', '190.000']]);
        $this->em()->clear();

        $result = $this->report(VatByRateReport::class, '2026-09-01', '2026-09-21');

        // A levy charged beside it is no VAT: FODEC is left out, as on the home.
        $vat = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId())?->getName();
        self::assertSame([['TVA19@19.000', $vat, '19.000', '1100.000', '209.000', 3]], array_map(static fn (array $line): array => [$line['row']->key, ...array_values($line['row']->cells)], $result->rows));
        self::assertSame([$this->summary()->vatTotal, '209.000'], [$result->totals['vat'], '209.000']);
        self::assertSame([['AV-1', '-19.000'], ['FAC-N', '190.000'], ['FAC-M', '38.000']], $this->documents(VatByRateReport::class, '2026-09-01', '2026-09-21', 'TVA19@19.000'));
        self::assertSame([], $this->documents(VatByRateReport::class, '2026-09-01', '2026-09-21', 'TVA19@19.000\' OR 1=1'));
    }

    public function testWhatEachCustomerKeptBackAtSourceAddsUpToTheHomesWithheld(): void
    {
        $this->issued('FAC-W1', 'Nabeul Bois', '2026-09-10', 30, '1000.000', withholding: '10.000');
        $this->issued('FAC-W2', 'Sans retenue', '2026-09-11', 30, '500.000');
        $old = $this->issued('FAC-W3', 'Transports Sahel', '2026-06-01', 30, '1000.000', withholding: '10.000');
        $this->credit($old, 'AV-W', '2026-09-12', '-200.000', withholding: '-2.000');
        $this->issued('FAC-W5', 'Sousse Print', '2026-08-25', 30, '900.000', withholding: '9.000');
        $this->issued('GLX-W', 'Autre', '2026-09-03', 0, '9999.000', $this->globex, withholding: '99.000');
        $this->em()->clear();

        $result = $this->report(WithholdingSufferedReport::class, '2026-09-01', '2026-09-21');

        self::assertSame([
            ['Nabeul Bois', '1000.000', '10.000', 1],
            ['Transports Sahel', '-200.000', '-2.000', 1],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows));
        self::assertSame([$this->summary()->withheldMonth, '8.000'], [$result->totals['withheld'], '8.000']);
        $sahel = $this->customers['Transports Sahel']->getId()->toRfc4122();
        self::assertSame([['AV-W', '-2.000']], $this->documents(WithholdingSufferedReport::class, '2026-09-01', '2026-09-21', $sahel));
    }

    /** @param class-string<DeclaresReport> $report */
    private function report(string $report, ?string $from = null, ?string $to = null, ReportComparison $comparison = ReportComparison::None, ?Establishment $establishment = null, bool $may = true): ReportResult
    {
        $declared = $this->declared($report);
        $period = null === $from || null === $to ? new ReportPeriod(new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-09-21')) : new ReportPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to));

        return new RunReport()->run($declared, $this->context($establishment, $may), $period, $comparison);
    }

    /** @param class-string<DeclaresReport> $report */
    private function declared(string $report): DeclaresReport
    {
        $declared = static::getContainer()->get($report);
        self::assertInstanceOf(DeclaresReport::class, $declared);

        return $declared;
    }

    /**
     * @param class-string<DeclaresReport> $report
     *
     * @return list<array{?string, string}> each document's number and what it brought
     */
    private function documents(string $report, ?string $from, ?string $to, string $row, ?Establishment $establishment = null): array
    {
        $period = new ReportPeriod(new \DateTimeImmutable($from ?? '2026-09-21'), new \DateTimeImmutable($to ?? '2026-09-21'));

        return array_map(static fn (ReportDocument $document): array => [$document->number, $document->amount], $this->declared($report)->documents($this->context($establishment), $period, $row));
    }

    private function context(?Establishment $establishment = null, bool $may = true): ReportContext
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return new ReportContext($company, new \DateTimeImmutable('2026-09-21'), static fn (string $permission): bool => $may, $establishment?->getId()->toRfc4122());
    }

    /** @return array<string, array<string, string|int|null>> the rows with a figure in the column, by key */
    private function rowsWith(ReportResult $result, string $column): array
    {
        $rows = [];
        foreach ($result->rows as $line) {
            if (null !== $line['row']->cells[$column]) {
                $rows[$line['row']->key] = $line['row']->cells;
            }
        }

        return $rows;
    }

    private function summary(): \App\Module\Invoices\Application\InvoiceSummary
    {
        $summarize = new SummarizeInvoices(static::getContainer()->get(InvoiceSummarySource::class), $this->clock, static::getContainer()->get(CurrencyScales::class));
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $summarize->handle($company, true);
    }

    private function draft(string $customer): void
    {
        $invoice = Invoice::create($this->company, $this->establishment($this->company), $this->customer($this->company, $customer), new InvoiceHeader(), [$this->line($this->company)], [], $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);
    }

    /** @param array<string, array{string, string}> $taxes the line taxes charged, by code: their base and amount */
    private function issued(string $number, string $customer, string $day, int $terms, string $total, ?Company $company = null, array $taxes = [], string $withholding = '0.000'): Invoice
    {
        $company ??= $this->company;
        $invoice = Invoice::create($company, $this->establishment($company), $this->customer($company, $customer), new InvoiceHeader(), [$this->line($company, array_keys($taxes))], [], $this->clock->now());
        $invoice->issue($this->issue($invoice, $number, $day, $terms), fn (): InvoiceFigures => $this->figures([$total], $total, $taxes, $withholding), $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $invoice;
    }

    /**
     * @param list<array{?Product, string}> $lines each line's product and quantity
     * @param list<string>                  $nets  each line's own net
     */
    private function sold(string $number, string $day, array $lines, array $nets, string $totalNet, ?Company $company = null): Invoice
    {
        $company ??= $this->company;
        $details = array_map(fn (array $line): InvoiceLineDetails => new InvoiceLineDetails($line[0], 'Article', $line[1], $line[0]?->getUnit() ?? $this->unit('C62', $company), '1', null, []), $lines);
        $invoice = Invoice::create($company, $this->establishment($company), $this->customer($company, 'Client '.$number), new InvoiceHeader(), $details, [], $this->clock->now());
        $invoice->issue($this->issue($invoice, $number, $day, 30), fn (): InvoiceFigures => $this->figures($nets, $totalNet), $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $invoice;
    }

    /** @param array<string, array{string, string}> $taxes */
    private function credit(Invoice $invoice, string $number, string $day, string $total, array $taxes = [], string $withholding = '0.000'): Invoice
    {
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->clock->now());
        $credit->issue($this->issue($credit, $number, $day, 0), fn (): InvoiceFigures => $this->figures([$total], $total, $taxes, $withholding), $this->clock->now());
        $invoice->credit($credit, $this->clock->now());
        $invoices = static::getContainer()->get(InvoiceRepository::class);
        $invoices->save($credit);
        $invoices->save($invoice);

        return $credit;
    }

    private function issue(Invoice $invoice, string $number, string $day, int $terms): InvoiceIssue
    {
        return new InvoiceIssue($number, new \DateTimeImmutable($day), $terms, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto'), KeptNames::of($invoice));
    }

    /** What one unit cost when the line at that position was issued, frozen at issue; set here as these products have none. */
    private function costed(Invoice $invoice, int $position, string $unitCost): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE invoice_line SET unit_cost = :cost WHERE invoice_id = :invoice AND position = :position', ['cost' => $unitCost, 'invoice' => $invoice->getId()->toRfc4122(), 'position' => $position]);
    }

    private function pay(Invoice $invoice, string $day, string $amount, PaymentMethod $method = PaymentMethod::Transfer): Payment
    {
        $payment = $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable($day), $amount, $method), new \DateTimeImmutable('2026-09-21'), 3, null, $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $payment;
    }

    /**
     * @param list<string>                         $nets  each line's own net
     * @param array<string, array{string, string}> $taxes the taxes charged, by code: their base and amount
     */
    private function figures(array $nets, string $totalNet, array $taxes = [], string $withholding = '0.000'): InvoiceFigures
    {
        $add = static fn (string $carry, string $figure): string => Decimal::format(Decimal::of($carry)->add(Decimal::of($figure)), 3);
        $sum = array_reduce($nets, $add, '0.000');
        $discount = Decimal::format(Decimal::of($sum)->sub(Decimal::of($totalNet)), 3);
        $rated = [];
        foreach ($taxes as $code => [$base, $amount]) {
            $rated[] = ['code' => $code, 'rate' => 'TVA19' === $code ? '19.000' : '1.000', 'base' => $base, 'amount' => $amount];
        }
        $gross = array_reduce(array_column($rated, 'amount'), $add, $totalNet);
        $due = Decimal::format(Decimal::of($gross)->sub(Decimal::of($withholding)), 3);

        return new InvoiceFigures($sum, $discount, $totalNet, $rated, '0.000', [], $gross, [], $withholding, $due, array_map(static fn (string $net): array => ['net' => $net, 'tax' => '0.000', 'gross' => $net], $nets));
    }

    private function customer(Company $company, string $name): Customer
    {
        $key = $company->getId()->toRfc4122().$name;
        if (isset($this->customers[$company === $this->company ? $name : $key])) {
            return $this->customers[$company === $this->company ? $name : $key];
        }
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, 'CLI-'.substr(md5($name), 0, 8), new CustomerProfile(CustomerKind::Company, $name), null, $regime, [], $this->clock->now());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $this->customers[$company === $this->company ? $name : $key] = $customer;
    }

    private function product(string $reference, string $name, Unit $unit, ?Company $company = null, ?ProductCategory $category = null): Product
    {
        $product = Product::create($company ?? $this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '1'), $unit, $category, [], $this->clock->now());
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function category(string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $category = ProductCategory::create($this->company, $name, $parent, $this->clock->now());
        $this->em()->persist($category);
        $this->em()->flush();

        return $category;
    }

    /** What the discounts took off, as issuing keeps it; set here as these documents are issued from figures given whole. */
    private function saved(Invoice $invoice, string $savings): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET savings = :savings WHERE id = :invoice', ['savings' => $savings, 'invoice' => $invoice->getId()->toRfc4122()]);
    }

    private function unit(string $code, ?Company $company = null): Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function establishment(Company $company): Establishment
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0];
    }

    /** @param list<string> $taxCodes */
    private function line(Company $company, array $taxCodes = []): InvoiceLineDetails
    {
        $components = static::getContainer()->get(TaxComponentRepository::class);
        $taxes = array_map(static fn (string $code): TaxComponent => $components->ofCodeInCompany($code, $company->getId()) ?? throw new \LogicException($code), $taxCodes);

        return new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62', $company), '1', null, $taxes);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
