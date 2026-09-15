<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNoteNumberTaken;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteCancelled;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\RecordingDomainEvents;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class DeliveryNoteWorkflowTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryNumberingSeries $series;
    private InMemoryDeliveryNotes $notes;
    private InMemoryInvoices $invoices;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private RecordingDomainEvents $events;
    private DeliveryNoteWorkflow $workflow;
    private Company $company;
    private Company $globex;
    private Customer $customer;

    protected function setUp(): void
    {
        // Half past eleven at night in UTC is already the 15th in Tunis: the company's day is the one that counts.
        $this->clock = new MockClock('2026-09-14 23:30:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $this->units, $this->establishments, $this->series, ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->notes = new InMemoryDeliveryNotes();
        $this->invoices = new InMemoryInvoices();
        $this->audit = new InMemoryAuditTrail();
        $this->transactions = new FakeTransactions();
        $this->events = new RecordingDomainEvents($this->transactions);
        $this->workflow = new DeliveryNoteWorkflow(
            $this->notes,
            $this->invoices,
            new AllocateNumber($this->series, $this->transactions, $this->clock),
            $this->transactions,
            new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            $this->events,
            $this->audit,
            $this->clock,
        );
        $now = $this->clock->now();
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
    }

    public function testValidationNumbersTheNoteInOneTransactionAndTellsOnlyOnceItIsStored(): void
    {
        $first = $this->draft();
        $actor = Uuid::v7();

        self::assertSame($first, $this->workflow->validate($this->company, $first->getId(), $actor));

        self::assertSame([DeliveryNoteStatus::Validated, 'BL-2026-00001', '2026-09-15'], [$first->getStatus(), $first->getNumber(), $first->getIssueDate()?->format('Y-m-d')]);
        self::assertSame(1, $this->transactions->committed);
        $entry = $this->audit->entries[0];
        self::assertSame(
            ['delivery_note', $first->getId(), 'delivery_note.validated', $actor, ['number' => 'BL-2026-00001'], $this->company->getId()],
            [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId],
        );
        self::assertCount(1, $this->events->published);
        $event = $this->events->published[0];
        self::assertInstanceOf(DeliveryNoteValidated::class, $event);
        self::assertSame([$first->getId(), 'BL-2026-00001'], [$event->deliveryNoteId, $event->number]);
        self::assertEquals([new DeliveredQuantity(null, '2.000', $this->unit('C62')->getId())], $event->lines);
        self::assertSame([false], $this->events->whileInTransaction, 'published once the transaction is over');

        self::assertSame('BL-2026-00002', $this->workflow->validate($this->company, $this->draft()->getId(), null)->getNumber());
    }

    public function testWhatCannotBeValidatedStaysADraftAndTellsNobody(): void
    {
        $empty = $this->draft([]);
        try {
            $this->workflow->validate($this->company, $empty->getId(), null);
            self::fail('a note without a line was validated');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('lines', $refused->field);
        }

        $note = $this->draft();
        try {
            $this->workflow->validate($this->globex, $note->getId(), null);
            self::fail('another company validated the note');
        } catch (DeliveryNoteNotFound) {
        }

        $this->series->series = [];
        try {
            $this->workflow->validate($this->company, $note->getId(), null);
            self::fail('a note was validated with no series to number it');
        } catch (NoNumberingSeries) {
        }

        self::assertSame([[DeliveryNoteStatus::Draft, null], [DeliveryNoteStatus::Draft, null]], [[$empty->getStatus(), $empty->getNumber()], [$note->getStatus(), $note->getNumber()]]);
        self::assertSame([[], [], 0], [$this->audit->entries, $this->events->published, $this->transactions->committed]);
    }

    public function testANumberAnotherEstablishmentOfTheCompanyAlreadyGaveIsRefusedWithTheWayOut(): void
    {
        $branch = new ManageEstablishments($this->establishments, $this->series, ShippedFiscalPresets::presets(), new InMemoryAuditTrail(), $this->clock)
            ->create($this->company, new EstablishmentDetails('001', 'Sfax', null, null, null, null, null, null, false), null);
        $this->workflow->validate($this->company, $this->draft()->getId(), null);
        $fromTheBranch = $this->draft(establishment: $branch);

        try {
            $this->workflow->validate($this->company, $fromTheBranch->getId(), null);
            self::fail('one number was given twice in a company');
        } catch (DeliveryNoteNumberTaken $taken) {
            self::assertStringContainsString('BL-2026-00001', $taken->getMessage());
            self::assertStringContainsString('{EST}', $taken->getMessage());
        }

        self::assertSame([DeliveryNoteStatus::Draft, null], [$fromTheBranch->getStatus(), $fromTheBranch->getNumber()]);
        self::assertCount(1, $this->events->published);
        self::assertCount(1, $this->audit->entries);
    }

    public function testDeliveryDefaultsToTheCompanysTodayAndOnlyAValidatedNoteCancelledIsAnnounced(): void
    {
        $actor = Uuid::v7();
        $delivered = $this->workflow->validate($this->company, $this->draft()->getId(), null);
        try {
            $this->workflow->deliver($this->company, $delivered->getId(), new \DateTimeImmutable('2026-09-16'), $actor);
            self::fail('a delivery after the company\'s today was confirmed');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('deliveredOn', $refused->field);
        }

        $this->workflow->deliver($this->company, $delivered->getId(), null, $actor);

        self::assertSame([DeliveryNoteStatus::Delivered, '2026-09-15'], [$delivered->getStatus(), $delivered->getHeader()->deliveryDate?->format('Y-m-d')]);
        $entry = $this->audit->entries[1];
        self::assertSame(['delivery_note.delivered', ['deliveryDate' => '2026-09-15'], $actor], [$entry->action, $entry->changes, $entry->actorUserId]);
        try {
            $this->workflow->cancel($this->company, $delivered->getId(), $actor);
            self::fail('a delivered note was cancelled');
        } catch (DeliveryNoteTransitionRefused) {
        }

        $validated = $this->workflow->validate($this->company, $this->draft()->getId(), null);
        $this->workflow->cancel($this->company, $validated->getId(), $actor);
        $draft = $this->draft();
        $this->workflow->cancel($this->company, $draft->getId(), $actor);

        self::assertSame([[DeliveryNoteStatus::Cancelled, 'BL-2026-00002'], [DeliveryNoteStatus::Cancelled, null]], [[$validated->getStatus(), $validated->getNumber()], [$draft->getStatus(), $draft->getNumber()]]);
        self::assertEquals([
            new DeliveryNoteCancelled($validated->getId(), $this->company->getId(), $validated->getEstablishment()->getId(), 'BL-2026-00002'),
        ], \array_slice($this->events->published, 2), 'a cancelled draft announced nothing and tells nothing');
        self::assertSame(
            [['delivery_note.cancelled', [], $validated->getId()], ['delivery_note.cancelled', [], $draft->getId()]],
            [[$this->audit->entries[3]->action, $this->audit->entries[3]->changes, $this->audit->entries[3]->entityId], [$this->audit->entries[4]->action, $this->audit->entries[4]->changes, $this->audit->entries[4]->entityId]],
        );

        $this->expectException(DeliveryNoteNotFound::class);
        $this->workflow->deliver($this->globex, $delivered->getId(), null, null);
    }

    public function testARequestThatReadTheNoteBeforeAnotherChangedItIsRefusedOnceItHoldsTheNote(): void
    {
        $note = $this->draft();
        $readAsDraft = clone $note;
        $this->workflow->validate($this->company, $note->getId(), null);
        $this->notes->staleReads[$note->getId()->toRfc4122()] = $readAsDraft;
        try {
            $this->workflow->validate($this->company, $note->getId(), null);
            self::fail('a validated note was validated again from a copy read while it was a draft');
        } catch (DeliveryNoteNotDraft) {
        }

        $readAsValidated = clone $note;
        $this->notes->staleReads = [];
        $this->workflow->cancel($this->company, $note->getId(), null);
        $this->notes->staleReads[$note->getId()->toRfc4122()] = $readAsValidated;
        try {
            $this->workflow->cancel($this->company, $note->getId(), null);
            self::fail('a cancelled note was cancelled again from a copy read while it was validated');
        } catch (DeliveryNoteTransitionRefused) {
        }

        self::assertSame([DeliveryNoteStatus::Cancelled, 'BL-2026-00001'], [$note->getStatus(), $note->getNumber()]);
        self::assertSame(['delivery_note.validated', 'delivery_note.cancelled'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
        self::assertCount(2, $this->events->published, 'the note was announced validated once and cancelled once, so its stock moved once each way');
        self::assertSame('BL-2026-00002', $this->workflow->validate($this->company, $this->draft()->getId(), null)->getNumber(), 'the refused attempt took no number');
    }

    public function testANoteOnAnInvoiceThatIsNotCancelledIsNotCancelled(): void
    {
        $note = $this->workflow->validate($this->company, $this->draft()->getId(), null);
        $invoice = Invoice::create($this->company, $note->getEstablishment(), $this->customer, new InvoiceHeader(), [
            new InvoiceLineDetails(null, 'Pièce', '2', $this->unit('C62'), '10', null, [], $note->getLines()[0]->getId()),
        ], [], $this->clock->now());
        $this->invoices->save($invoice);

        try {
            $this->workflow->cancel($this->company, $note->getId(), null);
            self::fail('a note on a draft invoice was cancelled');
        } catch (DeliveryNoteTransitionRefused $refused) {
            self::assertStringContainsString('invoice', $refused->getMessage());
        }
        self::assertSame(DeliveryNoteStatus::Validated, $note->getStatus());

        $invoice->cancel($this->clock->now());
        $this->workflow->cancel($this->company, $note->getId(), null);
        self::assertSame(DeliveryNoteStatus::Cancelled, $note->getStatus(), 'a cancelled invoice holds no note');
    }

    /** @param list<DeliveryNoteLineDetails>|null $lines one line of two pieces when left out */
    private function draft(?array $lines = null, ?Establishment $establishment = null): DeliveryNote
    {
        $default = array_find($this->establishments->ofCompany($this->company->getId()), static fn (Establishment $e): bool => $e->isDefault());
        self::assertNotNull($default);
        $note = DeliveryNote::create($this->company, $establishment ?? $default, $this->customer, new DeliveryNoteHeader(), $lines ?? [
            new DeliveryNoteLineDetails(null, 'Pièce', '2', $this->unit('C62'), '10', []),
        ], $this->clock->now());
        $this->notes->save($note);

        return $note;
    }

    private function unit(string $code): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }
}
