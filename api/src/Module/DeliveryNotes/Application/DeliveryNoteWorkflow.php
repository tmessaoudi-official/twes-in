<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
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
use App\Shared\Application\Transactions;
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
final readonly class DeliveryNoteWorkflow
{
    public const string DOCUMENT_TYPE = 'delivery_note';
    public const string VALIDATED = 'delivery_note.validated';
    public const string DELIVERED = 'delivery_note.delivered';
    public const string CANCELLED = 'delivery_note.cancelled';

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
    ) {
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     * @throws NoNumberingSeries       when the note's establishment numbers no delivery note
     * @throws InvalidNumbering        when the company's day comes before the month of the series' last number
     * @throws DeliveryNoteNumberTaken when another establishment of the company already gave the number
     */
    public function validate(Company $company, Uuid $id, ?Uuid $actorUserId): DeliveryNote
    {
        // Held until this transaction ends and read as it stands, before the series: a request that read the draft
        // before another validated it is refused here, and takes no number.
        $note = $this->transactions->run(fn (): DeliveryNote => $this->numbered($company, $this->locked($company, $id), $actorUserId));
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
     */
    public function validateAndDeliver(Company $company, Uuid $id, ?Uuid $actorUserId): DeliveryNote
    {
        $note = $this->transactions->run(function () use ($company, $id, $actorUserId): DeliveryNote {
            $note = $this->numbered($company, $this->locked($company, $id), $actorUserId);

            return $this->delivered($company, $note, null, $actorUserId);
        });
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * @param \DateTimeImmutable|null $deliveredAt the moment the goods arrived; left out, now
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     * @throws InvalidDeliveryNote
     */
    public function deliver(Company $company, Uuid $id, ?\DateTimeImmutable $deliveredAt, ?Uuid $actorUserId): DeliveryNote
    {
        $note = $this->transactions->run(fn (): DeliveryNote => $this->delivered($company, $this->locked($company, $id), $deliveredAt, $actorUserId));
        $this->events->publish(...$note->releaseEvents());

        return $note;
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

    private function numbered(Company $company, DeliveryNote $note, ?Uuid $actorUserId): DeliveryNote
    {
        $note->assertDraft('is validated');
        // A draft put back from « Récemment supprimés » was never moved by a merge made meanwhile.
        if ($note->getCustomer()->isMergedAway()) {
            throw new InvalidDeliveryNote('customerId', $note->getCustomer()->whySwitchedOff());
        }
        $this->chargedByItsRegimes($company, $note);
        $this->stillDeliverable($company, $note);
        $allocated = $this->numbers->allocate($company, $note->getEstablishment(), self::DOCUMENT_TYPE);
        if ($this->notes->numberTaken($company->getId(), $allocated->number)) {
            throw new DeliveryNoteNumberTaken(\sprintf('The number %s is already on another delivery note of this company: give the delivery note series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $note->getEstablishment()->getCode()));
        }
        $printing = DeliveryNotePrinting::today($this->settings, $note);
        $units = array_map(static fn (DeliveryNoteLine $line): Unit => $line->getUnit(), $note->getLines());
        $note->validate($allocated->number, $allocated->issueDate, $printing, $this->names->now($company, $printing->language, $units), $this->clock->now());
        $this->totals->checked($note);
        $this->notes->save($note);
        $this->record($company, $note, self::VALIDATED, ['number' => $allocated->number], $actorUserId);

        return $note;
    }

    private function delivered(Company $company, DeliveryNote $note, ?\DateTimeImmutable $deliveredAt, ?Uuid $actorUserId): DeliveryNote
    {
        $now = $this->clock->now();
        $note->deliver($deliveredAt ?? $now, $now);
        $this->notes->save($note);
        $this->record($company, $note, self::DELIVERED, ['deliveryDate' => $note->getHeader()->deliveryDate?->format('Y-m-d')], $actorUserId);

        return $note;
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
                    throw (new InvalidDeliveryNote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode())))->within("lines[$index]");
                }
            }
        }
    }
}
