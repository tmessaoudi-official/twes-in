<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\Output;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Application\Delivery\DeliveryNotesOfInvoices;
use App\Module\Invoices\Application\Delivery\GoodsToDeliver;
use App\Module\Invoices\Application\Delivery\InvoiceDeliveries;
use App\Module\Invoices\Application\Delivery\InvoiceDeliveryNote;
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
 * How every invoices endpoint answers an invoice: its figures worked out, the delivery notes its lines were taken from,
 * so it links back to them, and, for an issued invoice of goods, the notes its goods leave with afterwards and what is
 * left to deliver; read once for a whole page. Those are told only to whoever may open them: a note's number is the
 * delivery notes', withheld as costs are without their right.
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
        private DeliveryNotesOfInvoices $deliveries,
    ) {
    }

    /** @param array<string, string> $sourceRoom what a draft's lines may take of their delivery note lines, by note line id */
    public function of(Company $company, Invoice $invoice, bool $withCosts = false, array $sourceRoom = []): InvoiceResource
    {
        return $this->delivered(InvoiceResource::of($invoice, $this->totals->figures($invoice), $withCosts, $sourceRoom, $this->notesOf($company, [$invoice])[0]), $invoice, $this->deliveriesOf($company, [$invoice]));
    }

    /**
     * @param list<Invoice> $invoices the company's
     *
     * @return list<InvoiceResource>
     */
    public function page(Company $company, array $invoices, bool $withCosts): array
    {
        $notes = $this->notesOf($company, $invoices);
        $deliveries = $this->deliveriesOf($company, $invoices);

        return array_map(fn (Invoice $invoice, array $its): InvoiceResource => $this->delivered(InvoiceResource::of($invoice, $this->totals->figures($invoice), $withCosts, [], $its), $invoice, $deliveries), $invoices, $notes);
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

    /**
     * What the notes deliver of the issued invoices of goods among these, read once; null for whoever may not open notes.
     *
     * @param list<Invoice> $invoices
     *
     * @return array<string, InvoiceDeliveries>|null by invoice id; an invoice no note delivers is absent
     */
    private function deliveriesOf(Company $company, array $invoices): ?array
    {
        if (!$this->mayOpen($company)) {
            return null;
        }
        $lineIds = [];
        foreach ($invoices as $invoice) {
            if (GoodsToDeliver::delivered($invoice)) {
                $lineIds[$invoice->getId()->toRfc4122()] = array_map(static fn (InvoiceLine $line): Uuid => $line->getId(), GoodsToDeliver::lines($invoice));
            }
        }

        return [] === $lineIds ? [] : $this->deliveries->ofInvoices($company, $lineIds);
    }

    /** @param array<string, InvoiceDeliveries>|null $deliveries */
    private function delivered(InvoiceResource $resource, Invoice $invoice, ?array $deliveries): InvoiceResource
    {
        if (null === $deliveries || !GoodsToDeliver::delivered($invoice)) {
            return $resource;
        }
        $its = $deliveries[$invoice->getId()->toRfc4122()] ?? null;
        $returned = GoodsToDeliver::returned($invoice);
        $some = false;
        $all = true;
        $resource->deliveries = [];
        foreach (GoodsToDeliver::lines($invoice) as $line) {
            $key = $line->getId()->toRfc4122();
            $back = Decimal::of($returned[$key] ?? '0');
            $onNotes = Decimal::of($its->onNotes[$key] ?? '0');
            $delivered = Decimal::of($its->delivered[$key] ?? '0');
            $owed = Decimal::of($line->getQuantity())->sub($back);
            $left = $owed->sub($onNotes);
            $some = $some || $delivered->compare(0) > 0;
            $all = $all && $delivered->compare($owed) >= 0;
            $resource->deliveries[] = [
                'lineId' => $key,
                'quantity' => Decimal::format(Decimal::of($line->getQuantity()), 3),
                'returned' => Decimal::format($back, 3),
                'onNotes' => Decimal::format($onNotes, 3),
                'delivered' => Decimal::format($delivered, 3),
                'left' => Decimal::format($left->compare(0) < 0 ? Decimal::zero() : $left, 3),
            ];
        }
        $resource->deliveredBy = array_map(static fn (InvoiceDeliveryNote $note): array => ['deliveryNoteId' => $note->id, 'number' => $note->number, 'status' => $note->status], $its->notes ?? []);
        $resource->delivery = match (true) {
            $some && $all => 'whole',
            $some => 'partly',
            default => 'none',
        };

        return $resource;
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
