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

    public function __construct(private DeliveryNoteTotals $totals, private InvoicedLines $invoiced, private CompanyGuard $guard, private ModuleStates $modules)
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

        return array_map(fn (DeliveryNote $note): DeliveryNoteResource => DeliveryNoteResource::of($note, $this->totals->of($note), self::carryingNote($note, $carrying)), $notes);
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
