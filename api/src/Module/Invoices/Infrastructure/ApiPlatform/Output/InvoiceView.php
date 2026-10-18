<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\Output;

use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\SourceDeliveryNote;
use App\Module\Invoices\Application\SourceDeliveryNoteLines;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceResource;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\Uid\Uuid;

/**
 * How every invoices endpoint answers an invoice: its figures worked out, and the delivery notes its lines were taken
 * from, so it links back to them; read once for a whole page. Those are told only to whoever may open them: a note's
 * number is the delivery notes', withheld as costs are without their right.
 */
final readonly class InvoiceView
{
    /** The delivery notes' module and right, named as they are stored: this module reaches theirs through no class. */
    private const string DELIVERY_NOTES = 'delivery_notes';
    private const string DELIVERY_NOTE_READ = 'delivery_note.read';

    public function __construct(
        private InvoiceTotals $totals,
        private SourceDeliveryNoteLines $sources,
        private CompanyGuard $guard,
        private ModuleStates $modules,
    ) {
    }

    /** @param array<string, string> $sourceRoom what a draft's lines may take of their delivery note lines, by note line id */
    public function of(Company $company, Invoice $invoice, bool $withCosts = false, array $sourceRoom = []): InvoiceResource
    {
        return InvoiceResource::of($invoice, $this->totals->figures($invoice), $withCosts, $sourceRoom, $this->notesOf($company, [$invoice])[0]);
    }

    /**
     * @param list<Invoice> $invoices the company's
     *
     * @return list<InvoiceResource>
     */
    public function page(Company $company, array $invoices, bool $withCosts): array
    {
        $notes = $this->notesOf($company, $invoices);

        return array_map(fn (Invoice $invoice, array $its): InvoiceResource => InvoiceResource::of($invoice, $this->totals->figures($invoice), $withCosts, [], $its), $invoices, $notes);
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return list<list<SourceDeliveryNote>> each invoice's notes, in the order given
     */
    private function notesOf(Company $company, array $invoices): array
    {
        $lines = array_map(self::sourceLines(...), $invoices);
        $all = array_values(array_unique(array_merge(...$lines)));
        $notes = [] === $all || !$this->mayOpen($company)
            ? []
            : $this->sources->notesOf(array_map(static fn (string $id): Uuid => Uuid::fromString($id), $all), $company->getId());

        return array_map(static fn (array $its): array => array_values(array_filter($notes, static fn (SourceDeliveryNote $note): bool => [] !== array_intersect($note->lineIds, $its))), $lines);
    }

    private function mayOpen(Company $company): bool
    {
        return $this->modules->isEnabled($company->getId(), self::DELIVERY_NOTES) && $this->guard->may($company, self::DELIVERY_NOTE_READ);
    }

    /** @return list<string> the delivery note lines the invoice's lines were taken from */
    private static function sourceLines(Invoice $invoice): array
    {
        return array_values(array_filter(array_map(static fn (InvoiceLine $line): ?string => $line->getSourceDeliveryNoteLineId()?->toRfc4122(), $invoice->getLines())));
    }
}
