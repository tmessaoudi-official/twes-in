<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InstrumentNotFound;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageCustomerCredit;
use App\Module\Invoices\Application\ManageInstruments;
use App\Module\Invoices\Application\ManagePayments;
use App\Module\Invoices\Domain\InstrumentDetails;
use App\Module\Invoices\Domain\InstrumentKind;
use App\Module\Invoices\Domain\InstrumentStatus;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Domain\PrintSettings;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomerCredits;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryPaymentInstruments;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageInstrumentsTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryInvoices $invoices;
    private InMemoryPaymentInstruments $instruments;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private ManageInstruments $manage;
    private ManagePayments $payments;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        // Half past eleven at night in UTC is already the 21st in Tunis: the company's day is the one that counts.
        $this->clock = new MockClock('2026-09-20 23:30:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->transactions = new FakeTransactions();
        $this->invoices = new InMemoryInvoices();
        $this->invoices->transactions = $this->transactions;
        $this->instruments = new InMemoryPaymentInstruments();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $credits = new InMemoryCustomerCredits();
        $this->payments = new ManagePayments($this->invoices, $credits, $this->transactions, ShippedFiscalPresets::scales(), $this->audit, $this->clock, $this->instruments);
        $credit = new ManageCustomerCredit($credits, new InMemoryCustomers(), $this->invoices, $this->transactions, ShippedFiscalPresets::scales(), $this->audit, $this->clock);
        $this->manage = new ManageInstruments($this->invoices, $this->instruments, $this->payments, $credit, $this->transactions, ShippedFiscalPresets::scales(), $this->audit, $this->clock);
    }

    public function testAChequeDatedAheadLeavesTheInvoiceDueAndIsAudited(): void
    {
        $invoice = $this->issued($this->company);
        $actor = Uuid::v7();

        $cheque = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-11-15'), '500', ' Banque de Tunisie ', 'CHQ-77'), $actor);

        self::assertSame([InstrumentStatus::Held, '500.000', 'Banque de Tunisie', 'CHQ-77', '2026-11-15'], [$cheque->getStatus(), $cheque->getAmount(), $cheque->getBank(), $cheque->getNumber(), $cheque->getDueOn()->format('Y-m-d')]);
        self::assertSame([InvoiceStatus::Issued, '0.000', '1178.100', []], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountPaid, $invoice->getIssuedFigures()?->amountDue, $invoice->getPayments()], 'an instrument is not money');
        self::assertSame([['invoice', $invoice->getId(), ManageInstruments::RECEIVED, $actor, ['instrumentId' => $cheque->getId()->toRfc4122(), 'kind' => 'check', 'dueOn' => '2026-11-15', 'amount' => '500.000'], $this->company->getId()]], $this->audited());
    }

    public function testCashingItRecordsThePaymentOnTheCompanysDayByTheWayItWasPaid(): void
    {
        $invoice = $this->issued($this->company);
        $cheque = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-09-21'), '500', null, 'CHQ-77'), null);
        $traite = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Draft, new \DateTimeImmutable('2026-12-01'), '300', null, 'TR-5'), null);
        $this->manage->deposit($this->company, $invoice->getId(), $cheque->getId(), null);

        $this->manage->cash($this->company, $invoice->getId(), $cheque->getId(), null);
        $this->manage->cash($this->company, $invoice->getId(), $traite->getId(), null);

        self::assertSame([InstrumentStatus::Cashed, InstrumentStatus::Cashed], [$cheque->getStatus(), $traite->getStatus()]);
        self::assertSame([InvoiceStatus::PartiallyPaid, '800.000', '378.100'], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountPaid, $invoice->getIssuedFigures()?->amountDue]);
        $payments = $invoice->getPayments();
        self::assertSame([['2026-09-21', '500.000', PaymentMethod::Check, 'CHQ-77'], ['2026-09-21', '300.000', PaymentMethod::Other, 'TR-5']], array_map(static fn ($payment): array => [$payment->getDate()->format('Y-m-d'), $payment->getAmount(), $payment->getMethod(), $payment->getReference()], $payments), 'the 21st is already today in Tunis');
        self::assertSame('2026-09-21', $cheque->getSettledOn()?->format('Y-m-d'));

        $this->expectException(InvoiceTransitionRefused::class);
        $this->manage->cash($this->company, $invoice->getId(), $cheque->getId(), null);
    }

    public function testAnUnpaidInstrumentLeavesATraceAndNoPayment(): void
    {
        $invoice = $this->issued($this->company);
        $cheque = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-09-25'), '500'), null);
        $this->manage->deposit($this->company, $invoice->getId(), $cheque->getId(), null);

        $this->manage->refuse($this->company, $invoice->getId(), $cheque->getId(), null);

        self::assertSame([InstrumentStatus::Unpaid, '2026-09-21', [], '1178.100'], [$cheque->getStatus(), $cheque->getSettledOn()?->format('Y-m-d'), $invoice->getPayments(), $invoice->getIssuedFigures()?->amountDue]);
        // What came back unpaid no longer promises anything, so the whole due can be covered again.
        $again = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-25'), '1178.1'), null);
        self::assertSame('1178.100', $again->getAmount());
    }

    public function testTheOpenInstrumentsNeverPromiseMoreThanIsStillDue(): void
    {
        $invoice = $this->issued($this->company);
        $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '1000'), null);

        $this->expectException(InvalidInvoice::class);
        $this->expectExceptionMessage('178.100');
        $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Draft, new \DateTimeImmutable('2026-10-01'), '178.101'), null);
    }

    public function testOnlyAHeldOneIsDepositedOrTakenOutOfThePortfolio(): void
    {
        $invoice = $this->issued($this->company);
        $held = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '100'), null);
        $deposited = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '100'), null);
        $this->manage->deposit($this->company, $invoice->getId(), $deposited->getId(), null);

        foreach ([
            'a deposited one deposited again' => fn () => $this->manage->deposit($this->company, $invoice->getId(), $deposited->getId(), null),
            'a deposited one deleted' => fn () => $this->manage->delete($this->company, $invoice->getId(), $deposited->getId(), null),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case was accepted");
            } catch (InvoiceTransitionRefused) {
                self::assertSame(InstrumentStatus::Deposited, $deposited->getStatus(), $case);
            }
        }

        $this->manage->delete($this->company, $invoice->getId(), $held->getId(), null);
        self::assertSame([$deposited], $this->instruments->instruments);
    }

    public function testAPaymentThatCashedOneIsKept(): void
    {
        $invoice = $this->issued($this->company);
        $cheque = $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-09-21'), '500'), null);
        $this->manage->cash($this->company, $invoice->getId(), $cheque->getId(), null);
        $payment = $cheque->getPayment();
        self::assertNotNull($payment);

        $this->expectException(InvoiceTransitionRefused::class);
        $this->payments->delete($this->company, $invoice->getId(), $payment->getId(), null);
    }

    public function testWhatIsRefusedOrNotTheCompanysLeavesNoTrace(): void
    {
        $invoice = $this->issued($this->company);
        $theirs = $this->issued($this->globex);
        $ours = new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '10');

        foreach ([
            'an instrument finer than the currency' => [InvalidInvoice::class, fn () => $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '10.0001'), null)],
            'a day before the issue day' => [InvalidInvoice::class, fn () => $this->manage->receive($this->company, $invoice->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-09-14'), '10'), null)],
            'another company\'s invoice' => [InvoiceNotFound::class, fn () => $this->manage->receive($this->company, $theirs->getId(), $ours, null)],
            'an absent instrument' => [InstrumentNotFound::class, fn () => $this->manage->cash($this->company, $invoice->getId(), Uuid::v7(), null)],
        ] as $case => [$expected, $attempt]) {
            try {
                $attempt();
                self::fail("$case was accepted");
            } catch (\RuntimeException|\DomainException $refused) {
                self::assertInstanceOf($expected, $refused, $case);
            }
        }
        self::assertSame([[], []], [$this->audit->entries, $this->instruments->instruments]);
    }

    public function testTheOtherCompanysInstrumentIsNotFoundThroughMyInvoice(): void
    {
        $mine = $this->issued($this->company);
        $theirs = $this->issued($this->globex);
        $foreign = $this->manage->receive($this->globex, $theirs->getId(), new InstrumentDetails(InstrumentKind::Check, new \DateTimeImmutable('2026-10-01'), '10'), null);

        $this->expectException(InstrumentNotFound::class);
        $this->manage->deposit($this->company, $mine->getId(), $foreign->getId(), null);
    }

    /** @return list<list<mixed>> */
    private function audited(): array
    {
        return array_map(static fn (AuditEntry $entry): array => [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId], $this->audit->entries);
    }

    private function issued(Company $company): Invoice
    {
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now());
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $this->clock->now());
        $unit = $this->units->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $invoice = Invoice::create($company, $this->establishments->ofCompany($company->getId())[0], $customer, new InvoiceHeader(), [new InvoiceLineDetails(null, 'Pièce', '1', $unit, '1000', null, [])], [], $this->clock->now());
        $invoice->issue(
            new InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), 30, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto')),
            static fn (Invoice $issuing): InvoiceFigures => new InvoiceFigures('1000.000', '0.000', '1000.000', [], '190.000', [], '1190.000', [['code' => 'RS1', 'rate' => '1.000', 'base' => '1190.000', 'amount' => '11.900']], '11.900', '1178.100', [['net' => '1000.000', 'tax' => '190.000', 'gross' => '1190.000']]),
            $this->clock->now(),
        );
        $this->invoices->save($invoice);

        return $invoice;
    }
}
