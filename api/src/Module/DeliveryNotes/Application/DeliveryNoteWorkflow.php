<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Model\Unit;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceLines;
use App\Module\DeliveryNotes\Application\Quotes\QuoteLines;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Application\ShortSales\SaleStock;
use App\Shared\Application\ShortSales\ShortOfStock;
use App\Shared\Application\ShortSales\ShortSaleConsent;
use App\Shared\Application\ShortSales\SoldLine;
use App\Shared\Application\Transactions;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\ReceiverRule;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A delivery note past its draft: validated (numbered, in the transaction that stores it), delivered, or cancelled
 * unless an invoice that is not cancelled carries it. What a note records happening is published once it is stored,
 * and every move is audited.
 */
final readonly class DeliveryNoteWorkflow implements DeclaresAuditActions
{
    public const string DOCUMENT_TYPE = 'delivery_note';
    public const string VALIDATED = 'delivery_note.validated';
    public const string DELIVERED = 'delivery_note.delivered';
    public const string CANCELLED = 'delivery_note.cancelled';
    public const string RECEIVER_CORRECTED = 'delivery_note.receiver_corrected';

    public function __construct(
        private DeliveryNoteRepository $notes,
        private InvoicedLines $invoiced,
        private AllocateNumber $numbers,
        private Transactions $transactions,
        private DeliveryNoteTotals $totals,
        private DomainEvents $events,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private ReadSetting $settings,
        private DocumentNames $names,
        private ExcludedTaxFamilies $excluded,
        private QuoteLines $quoteLines,
        private InvoiceLines $invoiceLines,
        private ChooseReceiver $receivers,
        private SaleStock $stock,
    ) {
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     * @throws NoNumberingSeries       when the note's establishment numbers no delivery note
     * @throws InvalidNumbering        when the company's day comes before the month of the series' last number
     * @throws DeliveryNoteNumberTaken when another establishment of the company already gave the number
     * @throws ShortOfStock            when « Sortie sans stock enregistré » refuses what it takes, or asks it confirmed
     */
    public function validate(Company $company, Uuid $id, ?Uuid $actorUserId, ?ShortSaleConsent $consent = null): DeliveryNote
    {
        // Held until this transaction ends and read as it stands, before the series: a request that read the draft
        // before another validated it is refused here, and takes no number.
        $note = $this->transactions->run(fn (): DeliveryNote => $this->numbered($company, $this->locked($company, $id), $actorUserId, null, $consent));
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * Numbers a draft and marks it delivered now, in one transaction recording both moves: the two steps a shop that
     * hands the goods over as it writes the note would otherwise take one after the other. Either both happen or none.
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     * @throws NoNumberingSeries
     * @throws InvalidNumbering
     * @throws DeliveryNoteNumberTaken
     * @throws DeliveryNoteTransitionRefused
     * @throws ShortOfStock
     */
    public function validateAndDeliver(Company $company, Uuid $id, ?Uuid $actorUserId, ?ReceiverChoice $receivedBy = null, ?ShortSaleConsent $consent = null): DeliveryNote
    {
        $note = $this->transactions->run(function () use ($company, $id, $actorUserId, $receivedBy, $consent): DeliveryNote {
            $note = $this->locked($company, $id);
            // Named as it is validated, so the note's issued print says who received.
            $receiver = null === $receivedBy ? null : $this->receiver($company, $note, $receivedBy, $actorUserId);
            $note = $this->numbered($company, $note, $actorUserId, $receiver, $consent);

            return $this->delivered($company, $note, null, null, $actorUserId);
        });
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * @param \DateTimeImmutable|null $deliveredAt the moment the goods arrived; left out, now
     * @param ReceiverChoice|null     $receivedBy  who received them; left out, whoever the draft named
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     * @throws InvalidDeliveryNote
     */
    public function deliver(Company $company, Uuid $id, ?\DateTimeImmutable $deliveredAt, ?Uuid $actorUserId, ?ReceiverChoice $receivedBy = null): DeliveryNote
    {
        $note = $this->transactions->run(fn (): DeliveryNote => $this->delivered($company, $this->locked($company, $id), $deliveredAt, $receivedBy, $actorUserId));
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * Who received a numbered note's goods, put right, or nobody when someone was named by mistake. Recorded by the field
     * it changed, never the name: the journal keeps no one's name.
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     * @throws InvalidDeliveryNote
     */
    public function correctReceiver(Company $company, Uuid $id, ?ReceiverChoice $receivedBy, ?Uuid $actorUserId): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $id, $receivedBy, $actorUserId): DeliveryNote {
            $note = $this->locked($company, $id);
            $receiver = $this->receiver($company, $note, $receivedBy, $actorUserId);
            $refused = DeliveryNoteSettings::receiverRule($this->settings, $note)->refusal($receiver);
            if (null !== $refused) {
                throw new InvalidDeliveryNote('receivedBy', $refused->message, $refused->reason, $refused->params);
            }
            if ($note->correctReceiver($receiver, $this->clock->now())) {
                $this->notes->save($note);
                $this->record($company, $note, self::RECEIVER_CORRECTED, ['fields' => ['receivedBy']], $actorUserId);
            }

            return $note;
        });
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): DeliveryNote
    {
        // The note is held while the invoices carrying it are read: a conversion that locks it waits, or is waited for.
        $note = $this->transactions->run(function () use ($company, $id, $actorUserId): DeliveryNote {
            $note = $this->locked($company, $id);
            $lines = array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), $note->getLines());
            $invoice = [] === $lines ? null : ($this->invoiced->carrying($company->getId(), $lines)[0] ?? null);
            if (null !== $invoice) {
                throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is on the invoice %s: cancel that draft first.', $note->getNumber() ?? $note->getId()->toRfc4122(), $invoice->number ?? $invoice->id->toRfc4122()));
            }
            $note->cancel($this->clock->now());
            $this->notes->save($note);
            $this->record($company, $note, self::CANCELLED, [], $actorUserId);

            return $note;
        });
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    private function numbered(Company $company, DeliveryNote $note, ?Uuid $actorUserId, ?Receiver $receiver = null, ?ShortSaleConsent $consent = null): DeliveryNote
    {
        $note->assertDraft('is validated');
        // A draft put back from « Récemment supprimés » was never moved by a merge made meanwhile.
        if ($note->getCustomer()->isMergedAway()) {
            throw new InvalidDeliveryNote('customerId', $note->getCustomer()->whySwitchedOff(), ...$note->getCustomer()->switchedOffReason());
        }
        $this->chargedByItsRegimes($company, $note);
        $this->stillDeliverable($company, $note);
        $this->stockedEnough($company, $note, $consent ?? new ShortSaleConsent());
        $allocated = $this->numbers->allocate($company, $note->getEstablishment(), self::DOCUMENT_TYPE);
        if ($this->notes->numberTaken($company->getId(), $allocated->number)) {
            throw new DeliveryNoteNumberTaken(\sprintf('The number %s is already on another delivery note of this company: give the delivery note series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $note->getEstablishment()->getCode()));
        }
        $printing = DeliveryNotePrinting::today($this->settings, $note);
        $units = array_map(static fn (DeliveryNoteLine $line): Unit => $line->getUnit(), $note->getLines());
        $note->validate($allocated->number, $allocated->issueDate, $printing, $this->names->now($company, $printing->language, $units), $this->clock->now(), $receiver, DeliveryNoteSettings::receiverRule($this->settings, $note));
        $this->totals->checked($note);
        $this->notes->save($note);
        $this->record($company, $note, self::VALIDATED, ['number' => $allocated->number], $actorUserId);

        return $note;
    }

    private function delivered(Company $company, DeliveryNote $note, ?\DateTimeImmutable $deliveredAt, ?ReceiverChoice $receivedBy, ?Uuid $actorUserId): DeliveryNote
    {
        $now = $this->clock->now();
        $rule = DeliveryNoteSettings::receiverRule($this->settings, $note);
        // A choice naming nobody (the field emptied) records nobody; no choice at all keeps whoever the draft named, unless
        // « Reçu par » was switched off since: the dialog then shows no field and says the name is not kept.
        $receiver = null === $receivedBy
            ? (ReceiverRule::Off === $rule ? null : $note->getHeader()->receivedBy)
            : $this->receiver($company, $note, $receivedBy, $actorUserId);
        $note->deliver($deliveredAt ?? $now, $now, $rule, $receiver);
        $this->notes->save($note);
        $this->record($company, $note, self::DELIVERED, ['deliveryDate' => $note->getHeader()->deliveryDate?->format('Y-m-d')], $actorUserId);

        return $note;
    }

    /**
     * « Sortie sans stock enregistré », read before the series: a sale its rule refuses, or one it asks confirmed that
     * nobody who may confirmed, takes no number and moves nothing. A draft is never asked: its line stays red until then.
     *
     * @throws ShortOfStock
     */
    private function stockedEnough(Company $company, DeliveryNote $note, ShortSaleConsent $consent): void
    {
        $lines = [];
        foreach ($note->getLines() as $index => $line) {
            // A line handing over an issued invoice's goods takes nothing: the invoice took them.
            if (null === $line->getSourceInvoiceLineId()) {
                $lines[] = new SoldLine($index, $line->getProduct()?->getId(), $line->getQuantity(), $line->getUnit()->getId(), $line->getLotCode());
            }
        }
        $refused = [] === $lines ? null : $this->stock->shortfall($company, $note->getEstablishment()->getId(), $consent->roleId, $lines)->refusal($consent);
        if (null !== $refused) {
            throw $refused;
        }
    }

    /**
     * What the draft delivers of a quote or an invoice is checked again, under their locks, as it was when saved: a draft
     * put back from « Récemment supprimés », or one a credit note or an invoice of the whole quote overtook, would
     * otherwise deliver what is no longer left, its goods leaving stock and billed twice.
     *
     * @throws InvalidDeliveryNote
     */
    private function stillDeliverable(Company $company, DeliveryNote $note): void
    {
        $lines = array_map(static fn (DeliveryNoteLine $line): DeliveryNoteLineInput => new DeliveryNoteLineInput(
            $line->getProduct()?->getId(),
            $line->getDescription(),
            $line->getQuantity(),
            $line->getUnit()->getId(),
            $line->getUnitPriceNet(),
            array_map(static fn (DeliveryNoteLineTax $tax): Uuid => $tax->getTaxComponent()->getId(), $line->getTaxes()),
            $line->getLotCode(),
            $line->getSourceQuoteLineId(),
            $line->getSourceInvoiceLineId(),
        ), $note->getLines());
        $this->invoiceLines->resolved($company, $lines, $note->getCustomer(), $note->getEstablishment(), $note, true);
        $this->quoteLines->resolved($company, $lines, $note->getCustomer(), $note->getEstablishment(), $note, true);
    }

    /**
     * Who the person named, among the note's customer's contacts or typed; null when they named nobody; the receiver the
     * note already names when that is who they named.
     *
     * @throws InvalidDeliveryNote
     */
    private function receiver(Company $company, DeliveryNote $note, ?ReceiverChoice $receivedBy, ?Uuid $actorUserId): ?Receiver
    {
        $current = $note->getHeader()->receivedBy;
        if (null !== $receivedBy && $receivedBy->names($current)) {
            return $current;
        }
        $receiver = $this->receivers->receiver($company, $note->getCustomer()->getId(), $receivedBy, $actorUserId);
        if ($receiver instanceof Refusal) {
            throw new InvalidDeliveryNote('receivedBy', $receiver->message, $receiver->reason, $receiver->params);
        }

        return $receiver;
    }

    /** Held until the transaction ends and read as it stands: a status check on it cannot be overtaken by another request. */
    private function locked(Company $company, Uuid $id): DeliveryNote
    {
        return $this->notes->lockedOfIdsInCompany([$id], $company->getId())[0] ?? throw new DeliveryNoteNotFound();
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, DeliveryNote $note, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageDeliveryNotes::ENTITY_TYPE, $note->getId(), $action, $actorUserId, $changes, $company->getId()));
    }

    /**
     * Every tax the draft carries is still one its customer's regime and its company's charge: either may have changed
     * since the draft was saved, and the validation refuses rather than drop a tax, which would choose a fiscal answer for
     * the person validating.
     *
     * @throws InvalidDeliveryNote
     */
    private function chargedByItsRegimes(Company $company, DeliveryNote $note): void
    {
        $regime = $note->getCustomer()->getTaxRegime();
        foreach ($note->getLines() as $index => $line) {
            foreach ($line->getTaxes() as $tax) {
                $leftOutBy = $this->excluded->regimeLeavingOut($company, $regime, $tax->getTaxComponent()->getFamily());
                if (null !== $leftOutBy) {
                    throw (new InvalidDeliveryNote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()), 'regime_excludes_tax', ['tax' => $tax->getCode()]))->within("lines[$index]");
                }
            }
        }
    }

    public function auditActions(): array
    {
        return [
            self::CANCELLED => new Recorded(ManageDeliveryNotes::ENTITY_TYPE, AuditScope::Company),
            self::DELIVERED => new Recorded(ManageDeliveryNotes::ENTITY_TYPE, AuditScope::Company),
            self::VALIDATED => new Recorded(ManageDeliveryNotes::ENTITY_TYPE, AuditScope::Company),
            self::RECEIVER_CORRECTED => new Recorded(ManageDeliveryNotes::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
