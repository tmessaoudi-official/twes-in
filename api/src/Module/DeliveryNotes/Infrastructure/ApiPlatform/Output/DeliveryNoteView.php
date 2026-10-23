<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output;

use App\Module\DeliveryNotes\Application\CarryingInvoice;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\InvoicedLines;
use App\Module\DeliveryNotes\Application\Invoices\DeliverableInvoices;
use App\Module\DeliveryNotes\Application\Quotes\DeliverableQuotes;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\Uid\Uuid;

/**
 * How every delivery notes endpoint answers a note: its figures worked out, and the invoices that carry it, so the note
 * links to them; read once for a whole page. Those are told only to whoever may open them: an invoice's number and
 * status are the invoices', withheld as costs are without their right.
 */
final readonly class DeliveryNoteView
{
    private const string INVOICES = 'invoices';

    private const string QUOTES = 'quotes';
    private const string QUOTE_READ = 'quote.read';

    public function __construct(private DeliveryNoteTotals $totals, private InvoicedLines $invoiced, private CompanyGuard $guard, private ModuleStates $modules, private DeliverableQuotes $quotes, private DeliverableInvoices $deliverable)
    {
    }

    public function of(DeliveryNote $note): DeliveryNoteResource
    {
        return $this->page([$note])[0];
    }

    /**
     * @param list<DeliveryNote> $notes one company's
     *
     * @return list<DeliveryNoteResource>
     */
    public function page(array $notes): array
    {
        if ([] === $notes) {
            return [];
        }
        $company = $notes[0]->getCompany();
        $lines = array_merge(...array_map(static fn (DeliveryNote $note): array => array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), $note->getLines()), $notes));
        $mayOpen = $this->modules->isEnabled($company->getId(), self::INVOICES) && $this->guard->may($company, InvoicePermission::READ);
        $carrying = [] === $lines || !$mayOpen ? [] : $this->invoiced->carrying($company->getId(), $lines);

        $quoteOfLine = $this->quotesDelivered($notes);
        $invoiceOfLine = $mayOpen ? $this->invoicesDelivered($notes) : [];

        return array_map(fn (DeliveryNote $note): DeliveryNoteResource => DeliveryNoteResource::of($note, $this->totals->of($note), self::carryingNote($note, $carrying), self::quoteOf($note, $quoteOfLine), self::invoiceOf($note, $invoiceOfLine)), $notes);
    }

    /**
     * The issued invoices whose goods the notes hand over, by invoice line id; asked only for whoever may open invoices.
     *
     * @param non-empty-list<DeliveryNote> $notes
     *
     * @return array<string, array{invoiceId: string, number: string|null}>
     */
    private function invoicesDelivered(array $notes): array
    {
        $sources = [];
        foreach ($notes as $note) {
            foreach ($note->getLines() as $line) {
                $source = $line->getSourceInvoiceLineId();
                if (null !== $source) {
                    $sources[$source->toRfc4122()] = $source;
                }
            }
        }
        if ([] === $sources) {
            return [];
        }
        $invoiceOfLine = [];
        foreach ($this->deliverable->ofLines($notes[0]->getCompany(), array_values($sources), false) as $invoice) {
            foreach ($invoice->lines as $line) {
                $invoiceOfLine[$line->id->toRfc4122()] = ['invoiceId' => $invoice->id->toRfc4122(), 'number' => $invoice->number];
            }
        }

        return $invoiceOfLine;
    }

    /**
     * @param array<string, array{invoiceId: string, number: string|null}> $invoiceOfLine
     *
     * @return array{invoiceId: string, number: string|null}|null
     */
    private static function invoiceOf(DeliveryNote $note, array $invoiceOfLine): ?array
    {
        foreach ($note->getLines() as $line) {
            $source = $line->getSourceInvoiceLineId()?->toRfc4122();
            if (null !== $source && isset($invoiceOfLine[$source])) {
                return $invoiceOfLine[$source];
            }
        }

        return null;
    }

    /**
     * The quotes the notes deliver, by quote line id, told as invoices are: only to whoever may open them.
     *
     * @param non-empty-list<DeliveryNote> $notes
     *
     * @return array<string, array{quoteId: string, number: string|null}>
     */
    private function quotesDelivered(array $notes): array
    {
        $sources = [];
        foreach ($notes as $note) {
            foreach ($note->getLines() as $line) {
                $source = $line->getSourceQuoteLineId();
                if (null !== $source) {
                    $sources[$source->toRfc4122()] = $source;
                }
            }
        }
        $company = $notes[0]->getCompany();
        if ([] === $sources || !$this->modules->isEnabled($company->getId(), self::QUOTES) || !$this->guard->may($company, self::QUOTE_READ)) {
            return [];
        }
        $quoteOfLine = [];
        foreach ($this->quotes->ofLines($company, array_values($sources), false) as $quote) {
            foreach ($quote->lines as $line) {
                $quoteOfLine[$line->id->toRfc4122()] = ['quoteId' => $quote->id->toRfc4122(), 'number' => $quote->number];
            }
        }

        return $quoteOfLine;
    }

    /**
     * @param array<string, array{quoteId: string, number: string|null}> $quoteOfLine
     *
     * @return array{quoteId: string, number: string|null}|null
     */
    private static function quoteOf(DeliveryNote $note, array $quoteOfLine): ?array
    {
        foreach ($note->getLines() as $line) {
            $source = $line->getSourceQuoteLineId()?->toRfc4122();
            if (null !== $source && isset($quoteOfLine[$source])) {
                return $quoteOfLine[$source];
            }
        }

        return null;
    }

    /**
     * @param list<CarryingInvoice> $carrying
     *
     * @return list<CarryingInvoice> those carrying a line of this note, in the order given
     */
    private static function carryingNote(DeliveryNote $note, array $carrying): array
    {
        $lines = array_map(static fn (DeliveryNoteLine $line): string => $line->getId()->toRfc4122(), $note->getLines());

        return array_values(array_filter($carrying, static fn (CarryingInvoice $invoice): bool => [] !== array_intersect($invoice->deliveryNoteLineIds, $lines)));
    }
}
