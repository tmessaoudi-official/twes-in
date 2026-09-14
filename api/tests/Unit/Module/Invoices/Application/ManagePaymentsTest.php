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
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManagePayments;
use App\Module\Invoices\Application\PaymentNotFound;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Module\Invoices\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManagePaymentsTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryInvoices $invoices;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
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
        $this->audit = new InMemoryAuditTrail();
        $this->payments = new ManagePayments($this->invoices, $this->transactions, ShippedFiscalPresets::scales(), $this->audit, $this->clock);
    }

    public function testAPaymentIsRecordedOnTheCompanysDayInOneTransactionAndAudited(): void
    {
        $invoice = $this->issued($this->company);
        $actor = Uuid::v7();

        $payment = $this->payments->record($this->company, $invoice->getId(), new PaymentDetails(new \DateTimeImmutable('2026-09-21'), '100', PaymentMethod::Transfer, 'VIR-1'), $actor);

        self::assertSame([InvoiceStatus::PartiallyPaid, '100.000', '1078.100'], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountPaid, $invoice->getIssuedFigures()?->amountDue], 'the 21st is already today in Tunis');
        self::assertSame(1, $this->transactions->committed);
        self::assertTrue($actor->equals($payment->getRecordedBy()));
        self::assertSame([
            ['invoice', $invoice->getId(), ManagePayments::RECORDED, $actor, ['paymentId' => $payment->getId()->toRfc4122(), 'date' => '2026-09-21', 'amount' => '100.000', 'method' => 'transfer'], $this->company->getId()],
        ], $this->audited());
    }

    public function testDeletingAPaymentGivesItsAmountBackToWhatIsDueAndIsAudited(): void
    {
        $invoice = $this->issued($this->company);
        $payment = $this->payments->record($this->company, $invoice->getId(), new PaymentDetails(new \DateTimeImmutable('2026-09-16'), '1178.1', PaymentMethod::Cash), null);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        $actor = Uuid::v7();

        $this->payments->delete($this->company, $invoice->getId(), $payment->getId(), $actor);

        self::assertSame([InvoiceStatus::Issued, '0.000', '1178.100', []], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountPaid, $invoice->getIssuedFigures()?->amountDue, $invoice->getPayments()]);
        self::assertSame(['invoice', $invoice->getId(), ManagePayments::DELETED, $actor, ['paymentId' => $payment->getId()->toRfc4122(), 'date' => '2026-09-16', 'amount' => '1178.100', 'method' => 'cash'], $this->company->getId()], $this->audited()[1]);
        self::assertSame(2, $this->transactions->committed);

        $this->expectException(PaymentNotFound::class);
        $this->payments->delete($this->company, $invoice->getId(), $payment->getId(), $actor);
    }

    public function testWhatIsRefusedOrNotTheCompanysLeavesNoTrace(): void
    {
        $invoice = $this->issued($this->company);
        $theirs = $this->issued($this->globex);
        $details = new PaymentDetails(new \DateTimeImmutable('2026-09-21'), '10', PaymentMethod::Card);

        foreach ([
            'an overpayment' => [InvalidInvoice::class, fn () => $this->payments->record($this->company, $invoice->getId(), new PaymentDetails(new \DateTimeImmutable('2026-09-21'), '1178.101', PaymentMethod::Card), null)],
            'a day after the company\'s today' => [InvalidInvoice::class, fn () => $this->payments->record($this->company, $invoice->getId(), new PaymentDetails(new \DateTimeImmutable('2026-09-22'), '10', PaymentMethod::Card), null)],
            'another company\'s invoice' => [InvoiceNotFound::class, fn () => $this->payments->record($this->company, $theirs->getId(), $details, null)],
            'another company\'s invoice deleted from' => [InvoiceNotFound::class, fn () => $this->payments->delete($this->company, $theirs->getId(), Uuid::v7(), null)],
            'an absent payment' => [PaymentNotFound::class, fn () => $this->payments->delete($this->company, $invoice->getId(), Uuid::v7(), null)],
        ] as $case => [$expected, $attempt]) {
            try {
                $attempt();
                self::fail("$case was accepted");
            } catch (\RuntimeException|\DomainException $refused) {
                self::assertInstanceOf($expected, $refused, $case);
            }
        }
        self::assertSame([[], [], '0.000'], [$this->audit->entries, $invoice->getPayments(), $invoice->getIssuedFigures()?->amountPaid]);
    }

    public function testTheInvoiceIsLockedInsideTheTransactionThatPaysIt(): void
    {
        $invoice = $this->issued($this->company);
        $this->invoices->transactions = new FakeTransactions();

        $this->expectException(\LogicException::class);
        $this->payments->record($this->company, $invoice->getId(), new PaymentDetails(new \DateTimeImmutable('2026-09-21'), '10', PaymentMethod::Card), null);
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
            new InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), 30, 'fr', [], null, null, null),
            static fn (Invoice $issuing): InvoiceFigures => new InvoiceFigures('1000.000', '0.000', '1000.000', [], '190.000', [], '1190.000', [['code' => 'RS1', 'rate' => '1.000', 'base' => '1190.000', 'amount' => '11.900']], '11.900', '1178.100', [['net' => '1000.000', 'tax' => '190.000', 'gross' => '1190.000']]),
            $this->clock->now(),
        );
        $this->invoices->save($invoice);

        return $invoice;
    }
}
