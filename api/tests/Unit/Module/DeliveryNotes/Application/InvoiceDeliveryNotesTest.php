<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class InvoiceDeliveryNotesTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryNumberingSeries $series;
    private InMemoryDeliveryNotes $notes;
    private InMemoryInvoices $invoices;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private InvoiceDeliveryNotes $invoicing;
    private Company $company;
    private Company $globex;
    private Customer $customer;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, $this->series, ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->notes = new InMemoryDeliveryNotes();
        $this->invoices = new InMemoryInvoices();
        $this->audit = new InMemoryAuditTrail();
        $this->transactions = new FakeTransactions();
        // The notes are held while they are read, as the database holds their rows.
        $this->notes->transactions = $this->transactions;
        $manage = new ManageInvoices($this->invoices, new FakeTransactions(), new InMemoryCustomers(), new InMemoryProducts(), $this->units, $this->taxes, $this->establishments, new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()), $this->audit, $this->clock);
        $this->invoicing = new InvoiceDeliveryNotes($this->notes, $this->invoices, $manage, $this->transactions, $this->audit, $this->clock);
        $this->customer = $this->customer($this->company);
    }

    public function testAnInvoiceIsDraftedFromNotesOfOneCustomerCopyingTheirLinesInTheOrderTheyWereNumbered(): void
    {
        $first = $this->note('BL-2026-00001', [
            new DeliveryNoteLineDetails(null, 'Portable 14"', '2', $this->unit('C62'), '1250', [$this->tax('FODEC'), $this->tax('TVA19')]),
            new DeliveryNoteLineDetails(null, 'Transport', '1', $this->unit('C62'), '30', [$this->tax('TVA19')]),
        ], new DeliveryNoteHeader(customerReference: 'PO-7'));
        $first->deliver(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-15'), $this->clock->now());
        $second = $this->note('BL-2026-00002', header: new DeliveryNoteHeader(new \DateTimeImmutable('2026-09-12'), customerReference: 'PO-7'));
        $actor = Uuid::v7();

        $invoice = $this->invoicing->draftInvoice($this->company, [$second->getId(), $first->getId()], $actor);

        self::assertSame([$invoice], $this->invoices->invoices);
        self::assertSame([InvoiceStatus::Draft, $this->customer, $first->getEstablishment()], [$invoice->getStatus(), $invoice->getCustomer(), $invoice->getEstablishment()]);
        self::assertSame(['2026-09-15', 'PO-7', null], [$invoice->getHeader()->supplyDate?->format('Y-m-d'), $invoice->getHeader()->customerReference, $invoice->getHeader()->paymentTermsDays], 'supplied the day the last goods were delivered');
        $lines = $invoice->getLines();
        self::assertEquals(array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), [...$first->getLines(), ...$second->getLines()]), array_map(static fn (InvoiceLine $line): ?Uuid => $line->getSourceDeliveryNoteLineId(), $lines));
        self::assertSame(
            [['Portable 14"', '2.000', '1250.0000', null, ['FODEC', 'TVA19']], ['Transport', '1.000', '30.0000', null, ['TVA19']], ['Pièce', '1.000', '10.0000', null, []]],
            array_map(static fn (InvoiceLine $line): array => [$line->getDescription(), $line->getQuantity(), $line->getUnitPriceNet(), $line->getDiscountRate(), array_map(static fn (InvoiceLineTax $tax): string => $tax->getCode(), $line->getTaxes())], $lines),
        );
        self::assertSame(1, $this->transactions->committed);
        $entry = $this->audit->entries[0];
        self::assertSame(
            ['invoice', $invoice->getId(), 'invoice.created', $actor, ['deliveryNoteIds' => [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()]]],
            [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes],
        );
        self::assertSame([DeliveryNoteStatus::Delivered, DeliveryNoteStatus::Validated], [$first->getStatus(), $second->getStatus()], 'the notes wait for the invoice to be issued');

        $lone = $this->note('BL-2026-00003', header: new DeliveryNoteHeader(customerReference: 'PO-8'));
        $alone = $this->invoicing->draftInvoice($this->company, [$lone->getId()], null);
        self::assertSame([null, 'PO-8'], [$alone->getHeader()->supplyDate, $alone->getHeader()->customerReference]);
        $mixed = $this->invoicing->draftInvoice($this->company, [$this->note('BL-2026-00004', header: new DeliveryNoteHeader(customerReference: 'PO-8'))->getId(), $this->note('BL-2026-00005', header: new DeliveryNoteHeader(customerReference: 'PO-9'))->getId()], null);
        self::assertNull($mixed->getHeader()->customerReference, 'notes naming different references name none');
    }

    public function testWhatCannotBeInvoicedIsRefusedAndDraftsNothing(): void
    {
        $mine = $this->note('BL-2026-00001');
        $otherCustomers = $this->note('BL-2026-00002', customer: $this->customer($this->company, 'CLI-0002'));
        $branch = new ManageEstablishments($this->establishments, $this->series, ShippedFiscalPresets::presets(), new InMemoryAuditTrail(), $this->clock)
            ->create($this->company, new EstablishmentDetails('001', 'Sfax', null, null, null, null, null, null, false), null);
        $fromTheBranch = $this->note('BL-2026-00003', establishment: $branch);
        $theirs = $this->note('BL-2026-00001', company: $this->globex);

        foreach ([
            'no note' => [],
            'a note named twice' => [$mine->getId(), $mine->getId()],
            'a note of no one' => [Uuid::v7()],
            "another company's note" => [$theirs->getId()],
            'notes of two customers' => [$mine->getId(), $otherCustomers->getId()],
            'notes of two establishments' => [$mine->getId(), $fromTheBranch->getId()],
        ] as $case => $ids) {
            try {
                $this->invoicing->draftInvoice($this->company, $ids, null);
                self::fail("$case was invoiced");
            } catch (InvalidDeliveryNote $refused) {
                self::assertSame('deliveryNoteIds', $refused->field, $case);
            }
        }

        $draft = DeliveryNote::create($this->company, $this->establishment($this->company), $this->customer, new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', [])], $this->clock->now());
        $this->notes->save($draft);
        $cancelled = $this->note('BL-2026-00004');
        $cancelled->cancel($this->clock->now());
        $invoiced = $this->note('BL-2026-00005');
        $invoiced->markInvoiced(Uuid::v7(), $this->clock->now());
        foreach (['a draft' => $draft, 'a cancelled note' => $cancelled, 'an invoiced note' => $invoiced] as $case => $note) {
            try {
                $this->invoicing->draftInvoice($this->company, [$mine->getId(), $note->getId()], null);
                self::fail("$case was invoiced");
            } catch (DeliveryNoteTransitionRefused) {
            }
        }
        self::assertSame([[], []], [$this->invoices->invoices, $this->audit->entries]);

        $held = $this->invoicing->draftInvoice($this->company, [$mine->getId()], null);
        try {
            $this->invoicing->draftInvoice($this->company, [$mine->getId()], null);
            self::fail('a note on a draft invoice was invoiced again');
        } catch (DeliveryNoteTransitionRefused $refused) {
            self::assertStringContainsString('invoice', $refused->getMessage());
        }
        $held->cancel($this->clock->now());
        $this->invoicing->draftInvoice($this->company, [$mine->getId()], null);
        self::assertCount(2, $this->invoices->invoices, 'a cancelled draft holds no note');
    }

    public function testIssuingAnInvoiceMarksTheNotesOfItsLinesInvoicedAndLeavesWhatItCannotMarkAsItWas(): void
    {
        $first = $this->note('BL-2026-00001', [
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', []),
            new DeliveryNoteLineDetails(null, 'Pose', '1', $this->unit('C62'), '40', []),
        ]);
        $second = $this->note('BL-2026-00002');
        $cancelled = $this->note('BL-2026-00003');
        $cancelled->cancel($this->clock->now());
        $invoiceId = Uuid::v7();
        $lines = array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), [...$first->getLines(), ...$second->getLines(), ...$cancelled->getLines()]);
        $this->clock->modify('+1 hour');

        $left = $this->invoicing->markInvoiced($this->company->getId(), $invoiceId, 'FAC-2026-00001', $lines);

        self::assertSame([DeliveryNoteStatus::Invoiced, DeliveryNoteStatus::Invoiced, DeliveryNoteStatus::Cancelled], [$first->getStatus(), $second->getStatus(), $cancelled->getStatus()]);
        self::assertTrue($invoiceId->equals($first->getInvoicedByInvoiceId()) && $invoiceId->equals($second->getInvoicedByInvoiceId()));
        self::assertEquals($this->clock->now(), $first->getUpdatedAt());
        self::assertCount(1, $left);
        self::assertStringContainsString('BL-2026-00003', $left[0]);
        self::assertSame(1, $this->transactions->committed);
        self::assertSame(
            [
                ['delivery_note', $first->getId(), 'delivery_note.invoiced', null, ['invoiceId' => $invoiceId->toRfc4122(), 'number' => 'FAC-2026-00001'], $this->company->getId()],
                ['delivery_note', $second->getId(), 'delivery_note.invoiced', null, ['invoiceId' => $invoiceId->toRfc4122(), 'number' => 'FAC-2026-00001'], $this->company->getId()],
            ],
            array_map(static fn ($entry): array => [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId], $this->audit->entries),
        );

        self::assertSame([], $this->invoicing->markInvoiced($this->company->getId(), $invoiceId, 'FAC-2026-00001', \array_slice($lines, 0, 3)), 'told twice, the invoice marks nothing twice');
        self::assertSame([], $this->invoicing->markInvoiced($this->globex->getId(), Uuid::v7(), 'FAC-2026-00001', $lines), "another company's invoice marks none of this company's notes");
        self::assertCount(2, $this->audit->entries);
        self::assertTrue($invoiceId->equals($first->getInvoicedByInvoiceId()));
    }

    /** @param list<DeliveryNoteLineDetails>|null $lines one line of one piece when left out */
    private function note(string $number, ?array $lines = null, DeliveryNoteHeader $header = new DeliveryNoteHeader(), ?Customer $customer = null, ?Establishment $establishment = null, ?Company $company = null): DeliveryNote
    {
        $company ??= $this->company;
        $now = $this->clock->now();
        $note = DeliveryNote::create($company, $establishment ?? $this->establishment($company), $customer ?? ($company === $this->company ? $this->customer : $this->customer($company)), $header, $lines ?? [
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62', $company), '10', []),
        ], $now);
        $note->validate($number, new \DateTimeImmutable('2026-09-15'), $now);
        $note->releaseEvents();
        $this->notes->save($note);

        return $note;
    }

    private function customer(Company $company, string $number = 'CLI-0001'): Customer
    {
        $now = $this->clock->now();

        return Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
    }

    private function establishment(Company $company): Establishment
    {
        $default = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault());
        self::assertNotNull($default);

        return $default;
    }

    private function unit(string $code, ?Company $company = null): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }
}
