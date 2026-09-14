<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Domain\TaxComponent;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Delivery notes and the invoices they end on (docs/SPEC.md § 7, 2026-09-14). An invoice is drafted from validated or
 * delivered notes of one customer and one establishment, whose rows are held meanwhile, so that a note on an invoice that
 * is not cancelled is on no other. The notes turn invoiced once that invoice is issued, so a draft cancelled on the way
 * strands none. Invoices know delivery notes only by the ids of their lines; this is the one place the two meet.
 */
final readonly class InvoiceDeliveryNotes
{
    public const string INVOICED = 'delivery_note.invoiced';

    public function __construct(
        private DeliveryNoteRepository $notes,
        private InvoiceRepository $invoices,
        private ManageInvoices $invoicing,
        private Transactions $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A draft invoice copying the notes' lines, in the order the notes were numbered, each naming the line it invoices,
     * with its taxes; supplied on the last delivery day the notes name, for the customer's reference they share.
     *
     * @param list<Uuid> $noteIds
     *
     * @throws InvalidDeliveryNote           on `deliveryNoteIds`: none, one named twice, one that is not the company's,
     *                                       or notes of two customers or two establishments
     * @throws DeliveryNoteTransitionRefused when a note is not validated or delivered, or is on an invoice that is not cancelled
     * @throws InvalidInvoice                when what a note says no longer makes an invoice line
     */
    public function draftInvoice(Company $company, array $noteIds, ?Uuid $actorUserId): Invoice
    {
        if ([] === $noteIds) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'An invoice is drafted from at least one delivery note.');
        }
        $named = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $noteIds);
        if (\count(array_unique($named)) !== \count($named)) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'Each delivery note is named once.');
        }

        return $this->transactions->run(function () use ($company, $noteIds, $named, $actorUserId): Invoice {
            $notes = self::inNumberOrder($this->notes->lockedOfIdsInCompany($noteIds, $company->getId()));
            $found = array_map(static fn (DeliveryNote $note): string => $note->getId()->toRfc4122(), $notes);
            foreach ($named as $id) {
                if (!\in_array($id, $found, true)) {
                    throw new InvalidDeliveryNote('deliveryNoteIds', \sprintf('No delivery note of this company has the id %s.', $id));
                }
            }
            foreach ($notes as $note) {
                if (DeliveryNoteStatus::Validated !== $note->getStatus() && DeliveryNoteStatus::Delivered !== $note->getStatus()) {
                    throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is %s: only a validated or delivered note is invoiced.', self::reference($note), $note->getStatus()->value));
                }
            }
            $first = $notes[0];
            foreach ($notes as $note) {
                if (!$note->getCustomer()->getId()->equals($first->getCustomer()->getId())) {
                    throw new InvalidDeliveryNote('deliveryNoteIds', 'An invoice is drafted from delivery notes of one customer.');
                }
                if (!$note->getEstablishment()->getId()->equals($first->getEstablishment()->getId())) {
                    throw new InvalidDeliveryNote('deliveryNoteIds', 'An invoice is drafted from delivery notes of one establishment, whose series numbers it.');
                }
            }
            foreach ($notes as $note) {
                $held = $this->invoices->carryingDeliveryNoteLines($company->getId(), self::lineIds([$note]))[0] ?? null;
                if (null !== $held) {
                    throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is already on the invoice %s, which is not cancelled.', self::reference($note), $held->getNumber() ?? $held->getId()->toRfc4122()));
                }
            }

            $lines = [];
            foreach ($notes as $note) {
                foreach ($note->getLines() as $line) {
                    $lines[] = new InvoiceLineDetails(
                        $line->getProduct(),
                        $line->getDescription(),
                        $line->getQuantity(),
                        $line->getUnit(),
                        $line->getUnitPriceNet(),
                        null,
                        array_map(static fn (DeliveryNoteLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes()),
                        $line->getId(),
                    );
                }
            }
            $references = array_values(array_unique(array_map(static fn (DeliveryNote $note): string => $note->getHeader()->customerReference ?? '', $notes)));
            $header = new InvoiceHeader(self::lastDelivery($notes), customerReference: 1 === \count($references) ? $references[0] : null);

            return $this->invoicing->createFromLines($company, $first->getEstablishment(), $first->getCustomer(), $header, $lines, ['deliveryNoteIds' => $found], $actorUserId);
        });
    }

    /**
     * Marks the company's notes carrying these lines invoiced by an issued invoice, each audited; a note the invoice
     * already marked is left alone.
     *
     * @param list<Uuid> $lineIds the delivery note lines the invoice's lines came from
     *
     * @return list<string> why a note was left as it was, one sentence each; none when every note was marked
     */
    public function markInvoiced(Uuid $companyId, Uuid $invoiceId, string $number, array $lineIds): array
    {
        if ([] === $lineIds) {
            return [];
        }

        return $this->transactions->run(function () use ($companyId, $invoiceId, $number, $lineIds): array {
            $left = [];
            foreach (self::inNumberOrder($this->notes->lockedOfLineIdsInCompany($lineIds, $companyId)) as $note) {
                if ($invoiceId->equals($note->getInvoicedByInvoiceId())) {
                    continue;
                }
                try {
                    $note->markInvoiced($invoiceId, $this->clock->now());
                } catch (DeliveryNoteTransitionRefused $refused) {
                    $left[] = $refused->getMessage();
                    continue;
                }
                $this->notes->save($note);
                $this->audit->record(new AuditEntry(ManageDeliveryNotes::ENTITY_TYPE, $note->getId(), self::INVOICED, null, ['invoiceId' => $invoiceId->toRfc4122(), 'number' => $number], $companyId));
            }

            return $left;
        });
    }

    /**
     * @param list<DeliveryNote> $notes
     *
     * @return list<DeliveryNote> by issue day, then number
     */
    private static function inNumberOrder(array $notes): array
    {
        usort($notes, static fn (DeliveryNote $a, DeliveryNote $b): int => [$a->getIssueDate(), $a->getNumber()] <=> [$b->getIssueDate(), $b->getNumber()]);

        return $notes;
    }

    /**
     * @param list<DeliveryNote> $notes
     *
     * @return list<Uuid>
     */
    private static function lineIds(array $notes): array
    {
        $ids = [];
        foreach ($notes as $note) {
            foreach ($note->getLines() as $line) {
                $ids[] = $line->getId();
            }
        }

        return $ids;
    }

    /** @param list<DeliveryNote> $notes */
    private static function lastDelivery(array $notes): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($notes as $note) {
            $day = $note->getHeader()->deliveryDate;
            if (null !== $day && (null === $last || $day > $last)) {
                $last = $day;
            }
        }

        return $last;
    }

    private static function reference(DeliveryNote $note): string
    {
        return $note->getNumber() ?? $note->getId()->toRfc4122();
    }
}
