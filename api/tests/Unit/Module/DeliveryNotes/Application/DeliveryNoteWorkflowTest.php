<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNoteNumberTaken;
use App\Module\DeliveryNotes\Application\DeliveryNoteSettings;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Domain\Event\DeliveryNoteCancelled;
use App\Module\DeliveryNotes\Domain\Event\DeliveryNoteValidated;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveredQuantity;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNotePrint;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\InvoiceLineDetails;
use App\Module\Invoices\Infrastructure\DeliveryNotes\RepositoryInvoicedLines;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Application\TypedPhones;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Infrastructure\Intl\LibPhoneNumbers;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tests\Support\AllowingSaleStock;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryReceiverContacts;
use App\Tests\Support\InMemoryRecordVersions;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\NoInvoicesToDeliver;
use App\Tests\Support\NoQuotesToDeliver;
use App\Tests\Support\RecordingDomainEvents;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Uid\Uuid;

final class DeliveryNoteWorkflowTest extends TestCase
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
    private RecordingDomainEvents $events;
    private DeliveryNoteWorkflow $workflow;
    private ChangeSettings $change;
    private InMemoryReceiverContacts $contacts;
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
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes = new InMemoryTaxComponents(), $this->units, $this->establishments, $this->series, ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->notes = new InMemoryDeliveryNotes();
        $this->invoices = new InMemoryInvoices();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->events = new RecordingDomainEvents($this->transactions);
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings(\App\Tests\Support\ShippedLanguages::catalogue()), new DeliveryNoteSettings(), new PresentationSettings(\App\Tests\Support\ShippedLanguages::catalogue())]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($settingTransactions = new FakeTransactions()), $this->clock, $settingTransactions, new RecordingDomainEvents(), new TypedPhones(new LibPhoneNumbers(new IdentityTranslator())));
        $this->workflow = new DeliveryNoteWorkflow(
            $this->notes,
            new RepositoryInvoicedLines($this->invoices),
            new AllocateNumber($this->series, $this->transactions, $this->clock),
            $this->transactions,
            new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            $this->events,
            $this->audit,
            $this->clock,
            new ReadSetting($resolve),
            new DocumentNames($this->taxes),
            new ExcludedTaxFamilies(ShippedFiscalPresets::presets()),
            NoQuotesToDeliver::lines($this->notes),
            NoInvoicesToDeliver::lines($this->notes),
            new ChooseReceiver($this->contacts = new InMemoryReceiverContacts()),
            new AllowingSaleStock(),
        );
        $now = $this->clock->now();
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
    }

    /** A re-render (the note cancelled, or its PDF first rendered after the renderer failed) prints what validation kept. */
    public function testValidationKeepsTheLanguagePricesReceptionBlockNotesAndFormatsItsCustomerAndCompanySay(): void
    {
        $atCustomer = new SettingContext($this->company, customerId: $this->customer->getId());
        $this->change->change($atCustomer, 'document.language', SettingLevel::Customer, 'en', null);
        $this->change->change($atCustomer, 'delivery_note.show_prices', SettingLevel::Customer, false, null);
        $this->change->change(new SettingContext($this->company), 'document.printed_notes', SettingLevel::Company, 'Marchandise voyageant aux risques du client.', null);
        $this->change->change(new SettingContext($this->company), 'presentation.number-format', SettingLevel::Company, 'space-comma', null);

        $note = $this->workflow->validate($this->company, $this->draft()->getId(), null);

        self::assertEquals(
            new DeliveryNotePrint('en', false, true, new PrintSettings('Marchandise voyageant aux risques du client.', 'auto', 'space-comma', true)),
            $note->getPrintSettings(),
        );
    }

    /** What the note calls its units and every tax of the company, in its own language, is kept as it is validated. */
    public function testValidationKeepsTheNamesOfItsUnitsAndOfEveryTaxInTheNotesLanguage(): void
    {
        $this->change->change(new SettingContext($this->company, customerId: $this->customer->getId()), 'document.language', SettingLevel::Customer, 'ar', null);
        $draft = $this->draft();
        self::assertNull($draft->getPrintedNames(), 'a draft prints the names as they are');

        $note = $this->workflow->validate($this->company, $draft->getId(), null);

        $taxes = [];
        foreach ($this->taxes->ofCompany($this->company->getId()) as $component) {
            $taxes[$component->getCode()] = $component->nameIn('ar');
        }
        self::assertSame(['C62' => $this->unit('C62')->nameIn('ar')], $note->getPrintedNames()?->units);
        self::assertSame($taxes, $note->getPrintedNames()->taxes);
        self::assertSame('معلوم الطابع الجبائي', $note->getPrintedNames()?->taxes['TIMBRE'] ?? null);
    }

    public function testValidationNumbersTheNoteInOneTransactionAndTellsOnlyOnceItIsStored(): void
    {
        $first = $this->draft();
        $actor = Uuid::v7();

        self::assertSame($first, $this->workflow->validate($this->company, $first->getId(), $actor));

        self::assertSame([DeliveryNoteStatus::Validated, 'BL-2026-09-00001', '2026-09-15'], [$first->getStatus(), $first->getNumber(), $first->getIssueDate()?->format('Y-m-d')]);
        self::assertSame(1, $this->transactions->committed);
        $entry = $this->audit->entries[0];
        self::assertSame(
            ['delivery_note', $first->getId(), 'delivery_note.validated', $actor, ['number' => 'BL-2026-09-00001'], $this->company->getId()],
            [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId],
        );
        self::assertCount(1, $this->events->published);
        $event = $this->events->published[0];
        self::assertInstanceOf(DeliveryNoteValidated::class, $event);
        self::assertSame([$first->getId(), 'BL-2026-09-00001'], [$event->deliveryNoteId, $event->number]);
        self::assertEquals([new DeliveredQuantity(null, '2.000', $this->unit('C62')->getId())], $event->lines);
        self::assertSame([false], $this->events->whileInTransaction, 'published once the transaction is over');

        self::assertSame('BL-2026-09-00002', $this->workflow->validate($this->company, $this->draft()->getId(), null)->getNumber());
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

    /**
     * A draft saved before its customer's regime changed still carries the VAT that regime now leaves out: validated, it
     * would print the regime's mention over the VAT it charges. The validation is refused as the save is, naming the
     * line, and nothing is numbered; which tax goes is the person's to say.
     */
    public function testATaxTheRegimeNowLeavesOutRefusesTheValidationBeforeANumberIsTaken(): void
    {
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($vat);
        $note = $this->draft([new DeliveryNoteLineDetails(null, 'Pièce', '2', $this->unit('C62'), '10', [$vat])]);
        $now = $this->clock->now();
        $this->customer->revise($this->customer->getNumber(), $this->customer->getProfile(), $this->customer->getGroup(), new CustomerTaxRegime('TN', 'export', 'fiscal.regime.export', [TaxFamily::Vat], 'fiscal.mention.tn.export', 0, $now), [], $this->customer->isActive(), $now);

        try {
            $this->workflow->validateAndDeliver($this->company, $note->getId(), null);
            self::fail('a note charged VAT to a customer whose regime now leaves it out');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame(['lines[0].taxComponentIds', 'The export regime does not charge TVA19.'], [$refused->field, $refused->getMessage()]);
        }
        self::assertSame([DeliveryNoteStatus::Draft, null, [], 0], [$note->getStatus(), $note->getNumber(), $this->audit->entries, $this->transactions->committed]);
    }

    public function testANumberAnotherEstablishmentOfTheCompanyAlreadyGaveIsRefusedWithTheWayOut(): void
    {
        $branch = new ManageEstablishments($this->establishments, $this->series, ShippedFiscalPresets::presets(), new InMemoryAuditTrail($branchTransactions = new FakeTransactions()), $this->clock, $branchTransactions, new InMemoryRecordVersions(), $phones = new TypedPhones(new LibPhoneNumbers(new IdentityTranslator())), new \App\Shared\Application\StoredContacts($phones))
            ->create($this->company, new EstablishmentDetails('001', 'Sfax', null, null, null, null, null, null, false), null);
        // A new site gets its code into the format it copies; someone revising it back out is what this refusal still guards.
        foreach ($this->series->series as $series) {
            if ($series->getEstablishment()->getId()->equals($branch->getId()) && 'delivery_note' === $series->getDocumentType()) {
                $series->revise(new \App\Tenancy\Domain\ValueObject\NumberFormat('BL-{YYYY}-{MM}-{SEQ:5}'), $series->getResetPeriod(), 1, new \DateTimeImmutable());
            }
        }
        $this->workflow->validate($this->company, $this->draft()->getId(), null);
        $fromTheBranch = $this->draft(establishment: $branch);

        try {
            $this->workflow->validate($this->company, $fromTheBranch->getId(), null);
            self::fail('one number was given twice in a company');
        } catch (DeliveryNoteNumberTaken $taken) {
            self::assertStringContainsString('BL-2026-09-00001', $taken->getMessage());
            self::assertStringContainsString('{EST}', $taken->getMessage());
        }

        self::assertSame([DeliveryNoteStatus::Draft, null], [$fromTheBranch->getStatus(), $fromTheBranch->getNumber()]);
        self::assertCount(1, $this->events->published);
        self::assertCount(1, $this->audit->entries);
    }

    public function testDeliveryIsStampedNowOnTheCompanysDayAndOnlyAValidatedNoteCancelledIsAnnounced(): void
    {
        $actor = Uuid::v7();
        $delivered = $this->workflow->validate($this->company, $this->draft()->getId(), null);
        try {
            $this->workflow->deliver($this->company, $delivered->getId(), $this->clock->now()->modify('+1 minute'), $actor);
            self::fail('a delivery after now was confirmed');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('deliveredAt', $refused->field);
        }

        $this->workflow->deliver($this->company, $delivered->getId(), null, $actor);

        self::assertSame(
            [DeliveryNoteStatus::Delivered, '2026-09-14 23:30:00', '2026-09-15'],
            [$delivered->getStatus(), $delivered->getDeliveredAt()?->format('Y-m-d H:i:s'), $delivered->getHeader()->deliveryDate?->format('Y-m-d')],
        );
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

        self::assertSame([[DeliveryNoteStatus::Cancelled, 'BL-2026-09-00002'], [DeliveryNoteStatus::Cancelled, null]], [[$validated->getStatus(), $validated->getNumber()], [$draft->getStatus(), $draft->getNumber()]]);
        self::assertEquals([
            new DeliveryNoteCancelled($validated->getId(), $this->company->getId(), $validated->getEstablishment()->getId(), 'BL-2026-09-00002'),
        ], \array_slice($this->events->published, 2), 'a cancelled draft announced nothing and tells nothing');
        self::assertSame(
            [['delivery_note.cancelled', [], $validated->getId()], ['delivery_note.cancelled', [], $draft->getId()]],
            [[$this->audit->entries[3]->action, $this->audit->entries[3]->changes, $this->audit->entries[3]->entityId], [$this->audit->entries[4]->action, $this->audit->entries[4]->changes, $this->audit->entries[4]->entityId]],
        );

        $this->expectException(DeliveryNoteNotFound::class);
        $this->workflow->deliver($this->globex, $delivered->getId(), null, null);
    }

    public function testValidatingAndDeliveringIsOneTransactionRecordingBothMoves(): void
    {
        $note = $this->draft();
        $actor = Uuid::v7();

        self::assertSame($note, $this->workflow->validateAndDeliver($this->company, $note->getId(), $actor));

        self::assertSame(
            [DeliveryNoteStatus::Delivered, 'BL-2026-09-00001', '2026-09-15', '2026-09-15', '2026-09-14 23:30:00'],
            [$note->getStatus(), $note->getNumber(), $note->getIssueDate()?->format('Y-m-d'), $note->getHeader()->deliveryDate?->format('Y-m-d'), $note->getDeliveredAt()?->format('Y-m-d H:i:s')],
        );
        self::assertSame(1, $this->transactions->committed);
        self::assertSame(
            [['delivery_note.validated', ['number' => 'BL-2026-09-00001'], $actor], ['delivery_note.delivered', ['deliveryDate' => '2026-09-15'], $actor]],
            array_map(static fn ($entry): array => [$entry->action, $entry->changes, $entry->actorUserId], $this->audit->entries),
        );
        self::assertInstanceOf(DeliveryNoteValidated::class, $this->events->published[0]);
        self::assertNotContains(true, $this->events->whileInTransaction, 'published once the transaction is over');
    }

    /**
     * Who received: the customer's setting decides whether it is asked, a contact of the customer is copied by name, a
     * typed name may be added to its contacts, and a correction after the delivery is recorded by the field it changed,
     * never the name (the journal keeps no one's name).
     */
    public function testTheReceiverFollowsTheCustomersSettingAndIsCorrectedAfterTheDelivery(): void
    {
        $atCustomer = new SettingContext($this->company, customerId: $this->customer->getId());
        $this->change->change($atCustomer, 'delivery_note.receiver', SettingLevel::Customer, 'required', null);
        $actor = Uuid::v7();
        $nobody = $this->draft();
        try {
            $this->workflow->validateAndDeliver($this->company, $nobody->getId(), $actor);
            self::fail('a delivery naming nobody was taken for a customer that requires a receiver');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame(['receivedBy', 'receiver_required'], [$refused->field, $refused->reason]);
        }

        $ahmed = Uuid::v7();
        $this->contacts->contacts[$this->customer->getId()->toRfc4122()][$ahmed->toRfc4122()] = 'Ahmed Ben Salah';
        $note = $this->workflow->validateAndDeliver($this->company, $this->draft()->getId(), $actor, new ReceiverChoice($ahmed->toRfc4122()));
        self::assertEquals(Receiver::named('Ahmed Ben Salah', $ahmed), $note->getHeader()->receivedBy);

        $validated = $this->workflow->validate($this->company, $this->draft()->getId(), $actor);
        $delivered = $this->workflow->deliver($this->company, $validated->getId(), null, $actor, new ReceiverChoice(name: 'Sami Trabelsi', addToContacts: true));
        self::assertSame('Sami Trabelsi', $delivered->getHeader()->receivedBy?->name);
        self::assertSame([[$this->customer->getId()->toRfc4122(), 'Sami Trabelsi']], $this->contacts->added);

        $corrected = $this->workflow->correctReceiver($this->company, $delivered->getId(), new ReceiverChoice(name: 'Sami Trabelsy'), $actor);
        self::assertSame('Sami Trabelsy', $corrected->getHeader()->receivedBy?->name);
        $last = array_last($this->audit->entries);
        self::assertNotNull($last);
        self::assertSame([DeliveryNoteWorkflow::RECEIVER_CORRECTED, ['fields' => ['receivedBy']], $actor], [$last->action, $last->changes, $last->actorUserId]);
        $count = \count($this->audit->entries);
        $this->workflow->correctReceiver($this->company, $delivered->getId(), new ReceiverChoice(name: 'Sami Trabelsy'), $actor);
        self::assertCount($count, $this->audit->entries, 'the same name records nothing');

        $this->change->change($atCustomer, 'delivery_note.receiver', SettingLevel::Customer, 'off', null);
        try {
            $this->workflow->deliver($this->company, $this->workflow->validate($this->company, $this->draft()->getId(), $actor)->getId(), null, $actor, new ReceiverChoice(name: 'Sami'));
            self::fail('a receiver was taken for a customer whose setting is off');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame(['receivedBy', 'receiver_off'], [$refused->field, $refused->reason]);
        }
    }

    /**
     * A customer whose documents name nobody has nobody printed on the note's original either: a name its draft kept from
     * before « Reçu par » was switched off is dropped as the delivery drops it, and one sent with the validation is
     * refused, the contact it would add with it (its transaction undone).
     */
    public function testAValidationUnderASettingOffPrintsAndKeepsNobody(): void
    {
        $default = array_find($this->establishments->ofCompany($this->company->getId()), static fn (Establishment $e): bool => $e->isDefault());
        self::assertNotNull($default);
        $note = DeliveryNote::create($this->company, $default, $this->customer, new DeliveryNoteHeader()->withReceiver(Receiver::named('Ahmed', null)), [
            new DeliveryNoteLineDetails(null, 'Pièce', '2', $this->unit('C62'), '10', []),
        ], $this->clock->now());
        $this->notes->save($note);
        $atCustomer = new SettingContext($this->company, customerId: $this->customer->getId());
        $this->change->change($atCustomer, 'delivery_note.receiver', SettingLevel::Customer, 'off', null);

        $validated = $this->workflow->validate($this->company, $note->getId(), null);
        self::assertSame([null, null], [$validated->getPrintedReceiverName(), $validated->getHeader()->receivedBy]);

        try {
            $this->workflow->validateAndDeliver($this->company, $this->draft()->getId(), null, new ReceiverChoice(name: 'Sami', addToContacts: true));
            self::fail('a receiver was taken at validation for a customer whose setting is off');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame(['receivedBy', 'receiver_off'], [$refused->field, $refused->reason]);
        }
    }

    public function testWhatCannotBeValidatedIsNeitherNumberedNorDelivered(): void
    {
        $empty = $this->draft([]);
        try {
            $this->workflow->validateAndDeliver($this->company, $empty->getId(), null);
            self::fail('a note without a line was validated and delivered');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('lines', $refused->field);
        }
        $validated = $this->workflow->validate($this->company, $this->draft()->getId(), null);
        try {
            $this->workflow->validateAndDeliver($this->company, $validated->getId(), null);
            self::fail('a note already validated was validated again');
        } catch (DeliveryNoteNotDraft) {
        }

        self::assertSame([DeliveryNoteStatus::Draft, null], [$empty->getStatus(), $empty->getNumber()]);
        self::assertSame(DeliveryNoteStatus::Validated, $validated->getStatus());
        self::assertSame(['delivery_note.validated'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
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

        self::assertSame([DeliveryNoteStatus::Cancelled, 'BL-2026-09-00001'], [$note->getStatus(), $note->getNumber()]);
        self::assertSame(['delivery_note.validated', 'delivery_note.cancelled'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
        self::assertCount(2, $this->events->published, 'the note was announced validated once and cancelled once, so its stock moved once each way');
        self::assertSame('BL-2026-09-00002', $this->workflow->validate($this->company, $this->draft()->getId(), null)->getNumber(), 'the refused attempt took no number');
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
