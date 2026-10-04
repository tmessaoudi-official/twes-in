<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Products\Domain\LotCode;
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
     * With no `$quantities` every line is invoiced for what is left of it; with them only the lines named are, each for
     * the quantity given. What is left of a line is its quantity less what the company's invoices that are not
     * cancelled, drafts included, already take, so a draft holds what it took.
     *
     * @param list<Uuid>                 $noteIds
     * @param array<string, string>|null $quantities by delivery note line id
     *
     * @throws InvalidDeliveryNote           on `deliveryNoteIds`: none, one named twice, one that is not the company's,
     *                                       or notes of two customers or two establishments; on `quantities`: a line that
     *                                       is not one of the notes', or a quantity that is not above zero or is more than is left
     * @throws DeliveryNoteTransitionRefused when a note is not validated or delivered, or is on an invoice that is not cancelled
     * @throws InvalidInvoice                when what a note says no longer makes an invoice line
     */
    public function draftInvoice(Company $company, array $noteIds, ?Uuid $actorUserId, ?array $quantities = null): Invoice
    {
        if ([] === $noteIds) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'An invoice is drafted from at least one delivery note.');
        }
        $named = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $noteIds);
        if (\count(array_unique($named)) !== \count($named)) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'Each delivery note is named once.');
        }

        return $this->transactions->run(function () use ($company, $noteIds, $named, $actorUserId, $quantities): Invoice {
            [$notes, $lines, $header] = $this->plan($company, $noteIds, $named, $quantities);

            return $this->invoicing->createFromLines($company, $notes[0]->getEstablishment(), $notes[0]->getCustomer(), $header, $lines, ['deliveryNoteIds' => array_map(static fn (DeliveryNote $note): string => $note->getId()->toRfc4122(), $notes)], $actorUserId);
        });
    }

    /**
     * The same notes' lines added after those a DRAFT invoice already has, instead of starting a new invoice (docs/SPEC.md
     * § 7, 2026-10-04): the draft must be of the notes' customer and establishment, and what it already took of a line
     * counts as taken, so a line is never added twice over. An issued invoice is never touched: a credit note corrects it.
     *
     * @param list<Uuid>                 $noteIds
     * @param array<string, string>|null $quantities by delivery note line id
     *
     * @throws InvoiceNotFound               when the invoice is not the company's
     * @throws InvoiceNotDraft               when it is not a draft any more
     * @throws InvalidDeliveryNote           as `draftInvoice`, and on `invoiceId` when the notes are not of the draft's customer and establishment
     * @throws DeliveryNoteTransitionRefused as `draftInvoice`
     * @throws InvalidInvoice                when what a note says no longer makes an invoice line
     */
    public function addToDraft(Company $company, Uuid $invoiceId, array $noteIds, ?Uuid $actorUserId, ?array $quantities = null): Invoice
    {
        if ([] === $noteIds) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'Delivery notes are added to an invoice at least one at a time.');
        }
        $named = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $noteIds);
        if (\count(array_unique($named)) !== \count($named)) {
            throw new InvalidDeliveryNote('deliveryNoteIds', 'Each delivery note is named once.');
        }

        return $this->transactions->run(function () use ($company, $invoiceId, $noteIds, $named, $actorUserId, $quantities): Invoice {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            [$notes, $lines] = $this->plan($company, $noteIds, $named, $quantities);
            if (!$notes[0]->getCustomer()->getId()->equals($invoice->getCustomer()->getId()) || !$notes[0]->getEstablishment()->getId()->equals($invoice->getEstablishment()->getId())) {
                throw new InvalidDeliveryNote('invoiceId', 'Delivery notes are added to an invoice of their customer and their establishment.');
            }

            return $this->invoicing->appendLines($company, $invoiceId, $lines, ['deliveryNoteIds' => array_map(static fn (DeliveryNote $note): string => $note->getId()->toRfc4122(), $notes)], $actorUserId);
        });
    }

    /**
     * What the notes asked for come to, checked: the notes in the order they were numbered, the invoice lines they make
     * and the header they share. Runs inside the caller's transaction, which holds the notes' rows.
     *
     * @param list<Uuid>                 $noteIds
     * @param list<string>               $named
     * @param array<string, string>|null $quantities
     *
     * @return array{list<DeliveryNote>, list<InvoiceLineDetails>, InvoiceHeader}
     */
    private function plan(Company $company, array $noteIds, array $named, ?array $quantities): array
    {
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
        $taken = $this->invoices->invoicedQuantities($company->getId(), self::lineIds($notes));
        $left = [];
        foreach ($notes as $note) {
            foreach ($note->getLines() as $line) {
                $key = $line->getId()->toRfc4122();
                $left[$key] = Decimal::of($line->getQuantity())->sub(Decimal::of($taken[$key] ?? '0'));
            }
        }
        if (null !== $quantities) {
            foreach ($quantities as $lineId => $quantity) {
                if (!isset($left[$lineId])) {
                    throw new InvalidDeliveryNote('quantities', \sprintf('The line %s is not on the notes asked for.', $lineId));
                }
                if (!is_numeric($quantity) || Decimal::of($quantity)->compare(0) <= 0) {
                    throw new InvalidDeliveryNote('quantities', \sprintf('The quantity of the line %s is more than nothing.', $lineId));
                }
                if (Decimal::of($quantity)->compare($left[$lineId]) > 0) {
                    throw new InvalidDeliveryNote('quantities', \sprintf('Only %s of the line %s is left to invoice.', Decimal::format($left[$lineId], 3), $lineId));
                }
            }
        } elseif ([] === array_filter($left, static fn (\BcMath\Number $each): bool => $each->compare(0) > 0)) {
            $held = $this->invoices->carryingDeliveryNoteLines($company->getId(), self::lineIds($notes))[0] ?? null;
            throw new DeliveryNoteTransitionRefused(\sprintf('Nothing is left to invoice on these delivery notes%s.', null === $held ? '' : \sprintf(': they are on the invoice %s, which is not cancelled', $held->getNumber() ?? $held->getId()->toRfc4122())));
        }

        $lines = [];
        foreach ($notes as $note) {
            foreach ($note->getLines() as $line) {
                $key = $line->getId()->toRfc4122();
                $quantity = null === $quantities ? Decimal::format($left[$key], 3) : ($quantities[$key] ?? null);
                if (null === $quantity || (null === $quantities && $left[$key]->compare(0) <= 0)) {
                    continue;
                }
                $lines[] = new InvoiceLineDetails(
                    $line->getProduct(),
                    $line->getDescription(),
                    $quantity,
                    $line->getUnit(),
                    $line->getUnitPriceNet(),
                    null,
                    array_map(static fn (DeliveryNoteLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes()),
                    $line->getId(),
                    LotCode::carried($line->getProduct(), $line->getLotCode()),
                );
            }
        }
        $references = array_values(array_unique(array_map(static fn (DeliveryNote $note): string => $note->getHeader()->customerReference ?? '', $notes)));
        $header = new InvoiceHeader(self::lastDelivery($notes), customerReference: 1 === \count($references) ? $references[0] : null);

    return [$notes, $lines, $header];
}

    /**
     * What of a note is still to invoice, line by line: what the company's invoices that are not cancelled already take,
     * drafts included, and the rest.
     *
     * @return list<array{lineId: string, quantity: string, invoiced: string, left: string}>
     *
     * @throws DeliveryNoteNotFound
     */
    public function left(Company $company, Uuid $noteId): array
    {
        $note = $this->notes->ofIdInCompany($noteId, $company->getId()) ?? throw new DeliveryNoteNotFound();
        $taken = $this->invoices->invoicedQuantities($company->getId(), self::lineIds([$note]));
        $lines = [];
        foreach ($note->getLines() as $line) {
            $key = $line->getId()->toRfc4122();
            $invoiced = Decimal::of($taken[$key] ?? '0');
            $lines[] = [
                'lineId' => $key,
                'quantity' => Decimal::format(Decimal::of($line->getQuantity()), 3),
                'invoiced' => Decimal::format($invoiced, 3),
                'left' => Decimal::format(Decimal::of($line->getQuantity())->sub($invoiced), 3),
            ];
        }

        return $lines;
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
                // A note is invoiced once the invoices issued take all of it; until then the rest is still to invoice. One
                // that cannot be invoiced at all is not skipped here: marking it says why.
                if (DeliveryNoteStatus::Validated === $note->getStatus() || DeliveryNoteStatus::Delivered === $note->getStatus()) {
                    $issued = $this->invoices->invoicedQuantities($companyId, self::lineIds([$note]), true);
                    foreach ($note->getLines() as $line) {
                        if (Decimal::of($line->getQuantity())->compare(Decimal::of($issued[$line->getId()->toRfc4122()] ?? '0')) > 0) {
                            continue 2;
                        }
                    }
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
