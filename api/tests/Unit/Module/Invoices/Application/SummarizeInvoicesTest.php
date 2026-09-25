<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\SummarizeInvoices;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SummarizeInvoicesTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryInvoices $invoices;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        // Half past eleven at night in UTC is already the 21st in Tunis: every day below is counted from the 21st.
        $this->clock = new MockClock('2026-09-20 23:30:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->invoices = new InMemoryInvoices();
    }

    public function testTheHomeFiguresAreWorkedOutFromIssuedDocumentsOnTheCompanysDay(): void
    {
        $this->draft();
        // Not yet due: 1 000 net, VAT 190 and a levy of 10, issued this month.
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000', ['TVA19' => '190.000', 'FODEC' => '10.000']);
        // Due in three days, 200 of its 500 paid this month.
        $soon = $this->issued('FAC-S', 'Sousse Print', '2026-08-25', 30, '500.000');
        $this->pay($soon, '2026-09-02', '200');
        // Ten days late.
        $this->issued('FAC-O1', 'Pharmacie Ennasr', '2026-08-12', 30, '850.050');
        // Forty-two days late.
        $this->issued('FAC-O2', 'Garage Ben Arous', '2026-07-11', 30, '300.000');
        // Eighty-two days late, 190 paid in July and 119 credited by a credit note issued this month (VAT −19).
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1190.000', ['TVA19' => '190.000']);
        $this->pay($old, '2026-07-15', '190');
        $this->credit($old, 'AV-1', '2026-09-10', '-119.000', ['TVA19' => '-19.000']);
        // Paid in full across August and September.
        $paid = $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        $this->pay($paid, '2026-08-20', '400');
        $this->pay($paid, '2026-09-05', '600');
        // Another company's overdue invoice counts nowhere.
        $this->issued('GLX-1', 'Autre', '2026-05-01', 0, '9999.000', company: $this->globex);

        $summary = (new SummarizeInvoices($this->invoices, $this->clock, ShippedFiscalPresets::scales()))->handle($this->company);

        self::assertSame(['TND', 3, '2026-09-21'], [$summary->currency, $summary->currencyScale, $summary->today]);
        self::assertSame(['3531.050', '1500.000', '2031.050', 3, 82], [$summary->outstanding, $summary->notYetDue, $summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays]);
        self::assertSame([
            ['bucket' => 'not_due', 'amount' => '1500.000', 'count' => 2],
            ['bucket' => 'days_1_15', 'amount' => '850.050', 'count' => 1],
            ['bucket' => 'days_16_30', 'amount' => '0.000', 'count' => 0],
            ['bucket' => 'days_31_45', 'amount' => '300.000', 'count' => 1],
            ['bucket' => 'days_over_45', 'amount' => '881.000', 'count' => 1],
        ], $summary->aging);
        self::assertSame(
            [['FAC-O3', 'Transports Sahel', '2026-07-01', '881.000', 82], ['FAC-O2', 'Garage Ben Arous', '2026-08-10', '300.000', 42], ['FAC-O1', 'Pharmacie Ennasr', '2026-09-11', '850.050', 10], ['FAC-S', 'Sousse Print', '2026-09-24', '300.000', -3]],
            array_map(static fn (array $row): array => [$row['number'], $row['customerName'], $row['dueDate'], $row['amountDue'], $row['daysLate']], $summary->toChase),
        );
        self::assertSame([4, '2331.050'], [$summary->toChaseCount, $summary->toChaseAmount]);
        self::assertSame([
            ['month' => '2026-04', 'amount' => '0.000'],
            ['month' => '2026-05', 'amount' => '0.000'],
            ['month' => '2026-06', 'amount' => '0.000'],
            ['month' => '2026-07', 'amount' => '190.000'],
            ['month' => '2026-08', 'amount' => '400.000'],
            ['month' => '2026-09', 'amount' => '800.000'],
        ], $summary->collected);
        self::assertSame([[['code' => 'TVA19', 'rate' => '19.000', 'amount' => '171.000']], '171.000'], [$summary->vat, $summary->vatTotal]);
    }

    public function testAnInvoiceDueTodayIsNotLateAndOneDueInAWeekIsToChase(): void
    {
        $this->issued('FAC-T', 'Aujourd’hui', '2026-08-22', 30, '100.000');
        $this->issued('FAC-W', 'Semaine', '2026-08-29', 30, '200.000');
        $this->issued('FAC-L', 'Plus tard', '2026-08-30', 30, '400.000');

        $summary = (new SummarizeInvoices($this->invoices, $this->clock, ShippedFiscalPresets::scales()))->handle($this->company);

        self::assertSame(['0.000', 0, null], [$summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays]);
        self::assertSame([['FAC-T', 0], ['FAC-W', -7]], array_map(static fn (array $row): array => [$row['number'], $row['daysLate']], $summary->toChase));
    }

    public function testACompanyWithoutInvoicesReadsZeros(): void
    {
        $summary = (new SummarizeInvoices($this->invoices, $this->clock, ShippedFiscalPresets::scales()))->handle($this->company);

        self::assertSame(['0.000', '0.000', '0.000', 0, null, [], 0, '0.000', [], '0.000'], [$summary->outstanding, $summary->notYetDue, $summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays, $summary->toChase, $summary->toChaseCount, $summary->toChaseAmount, $summary->vat, $summary->vatTotal]);
        self::assertSame(['2026-04', '2026-09', '0.000'], [$summary->collected[0]['month'], $summary->collected[5]['month'], $summary->collected[5]['amount']]);
    }

    private function draft(): void
    {
        $this->invoices->save(Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $this->customer($this->company, 'Brouillon'), new InvoiceHeader(), [$this->line($this->company, [])], [], $this->clock->now()));
    }

    /** @param array<string, string> $taxes the line taxes charged, by code */
    private function issued(string $number, string $customer, string $day, int $terms, string $total, array $taxes = [], ?Company $company = null): Invoice
    {
        $company ??= $this->company;
        $invoice = Invoice::create($company, $this->establishments->ofCompany($company->getId())[0], $this->customer($company, $customer), new InvoiceHeader(), [$this->line($company, array_keys($taxes))], [], $this->clock->now());
        $invoice->issue(new InvoiceIssue($number, new \DateTimeImmutable($day), $terms, 'fr', [], null, null, null), fn (): InvoiceFigures => $this->figures($total, $taxes), $this->clock->now());
        $this->invoices->save($invoice);

        return $invoice;
    }

    /** @param array<string, string> $taxes */
    private function credit(Invoice $invoice, string $number, string $day, string $total, array $taxes): void
    {
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->clock->now());
        $credit->issue(new InvoiceIssue($number, new \DateTimeImmutable($day), 0, 'fr', [], null, null, null), fn (): InvoiceFigures => $this->figures($total, $taxes), $this->clock->now());
        $invoice->credit($credit, $this->clock->now());
        $this->invoices->save($credit);
    }

    private function pay(Invoice $invoice, string $day, string $amount): void
    {
        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable($day), $amount, PaymentMethod::Transfer), new \DateTimeImmutable('2026-09-21'), 3, null, $this->clock->now());
    }

    /** @param array<string, string> $taxes */
    private function figures(string $total, array $taxes): InvoiceFigures
    {
        $rated = [];
        foreach ($taxes as $code => $amount) {
            $rated[] = ['code' => $code, 'rate' => 'TVA19' === $code ? '19.000' : '1.000', 'base' => '0.000', 'amount' => $amount];
        }

        return new InvoiceFigures($total, '0.000', $total, $rated, '0.000', [], $total, [], '0.000', $total, [['net' => $total, 'tax' => '0.000', 'gross' => $total]]);
    }

    private function customer(Company $company, string $name): Customer
    {
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now());

        return Customer::create($company, 'CLI-'.substr(md5($name), 0, 8), new CustomerProfile(CustomerKind::Company, $name), null, $regime, [], $this->clock->now());
    }

    /** @param list<string> $taxCodes */
    private function line(Company $company, array $taxCodes): InvoiceLineDetails
    {
        $unit = $this->units->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $taxes = array_map(fn (string $code): TaxComponent => $this->taxes->ofCodeInCompany($code, $company->getId()) ?? throw new \LogicException($code), $taxCodes);

        return new InvoiceLineDetails(null, 'Pièce', '1', $unit, '1', null, $taxes);
    }
}
