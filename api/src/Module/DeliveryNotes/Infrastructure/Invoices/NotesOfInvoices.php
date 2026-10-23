<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Invoices;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\Invoices\Application\Delivery\DeliveryNotesOfInvoices;
use App\Module\Invoices\Application\Delivery\InvoiceDeliveries;
use App\Module\Invoices\Application\Delivery\InvoiceDeliveryNote;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the invoices' `DeliveryNotesOfInvoices` port from this module's notes, by the invoice lines their lines name. */
final readonly class NotesOfInvoices implements DeliveryNotesOfInvoices
{
    public function __construct(private DeliveryNoteRepository $notes)
    {
    }

    public function ofInvoices(Company $company, array $lineIdsByInvoice): array
    {
        $invoiceOfLine = [];
        foreach ($lineIdsByInvoice as $invoiceId => $ids) {
            foreach ($ids as $id) {
                $invoiceOfLine[$id->toRfc4122()] = (string) $invoiceId;
            }
        }
        if ([] === $invoiceOfLine) {
            return [];
        }
        $lineIds = array_map(static fn (string $id): Uuid => Uuid::fromString($id), array_keys($invoiceOfLine));
        $notesOf = [];
        foreach ($this->notes->deliveringInvoiceLines($company->getId(), $lineIds) as $note) {
            foreach (self::invoicesOf($note, $invoiceOfLine) as $invoiceId) {
                $notesOf[$invoiceId][] = new InvoiceDeliveryNote($note->getId()->toRfc4122(), $note->getNumber(), $note->getStatus()->value);
            }
        }
        // A page of invoices no note delivers, the common case, costs that one read: the sums are asked only once one does.
        if ([] === $notesOf) {
            return [];
        }
        $onNotes = $this->notes->invoiceLineQuantities($company->getId(), $lineIds);
        $delivered = $this->notes->invoiceLineQuantities($company->getId(), $lineIds, deliveredOnly: true);

        $deliveries = [];
        foreach ($lineIdsByInvoice as $invoiceId => $ids) {
            $key = (string) $invoiceId;
            if (!isset($notesOf[$key])) {
                continue;
            }
            $own = array_flip(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids));
            $deliveries[$key] = new InvoiceDeliveries($notesOf[$key], array_intersect_key($onNotes, $own), array_intersect_key($delivered, $own));
        }

        return $deliveries;
    }

    /**
     * @param array<string, string> $invoiceOfLine
     *
     * @return list<string> the invoices the note's lines deliver, each once
     */
    private static function invoicesOf(DeliveryNote $note, array $invoiceOfLine): array
    {
        $invoices = [];
        foreach ($note->getLines() as $line) {
            $source = $line->getSourceInvoiceLineId()?->toRfc4122();
            if (null !== $source && isset($invoiceOfLine[$source])) {
                $invoices[] = $invoiceOfLine[$source];
            }
        }

        return array_values(array_unique($invoices));
    }
}
