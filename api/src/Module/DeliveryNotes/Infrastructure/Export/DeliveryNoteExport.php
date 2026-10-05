<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteSearch;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\DeliveryNotePermission;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\DeliveryNoteSearchReader;
use App\Module\DeliveryNotes\Infrastructure\Module\DeliveryNotesModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The delivery notes list as a file (docs/SPEC.md § 7, row 60): one row per note, under the search, status, customer
 * and order the screen shows. Days go out as 2026-09-15 and amounts as the decimals the note holds, so a spreadsheet
 * sums and sorts them without guessing a locale. A draft has no number and no recorded customer yet, so its row names
 * the customer as it stands today.
 */
final readonly class DeliveryNoteExport implements DeclaresExport
{
    private const int BATCH = 200;
    private const string KEY = 'delivery-notes';

    public function __construct(private ManageDeliveryNotes $manage, private DeliveryNoteTotals $totals)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return DeliveryNotePermission::READ;
    }

    public function module(): string
    {
        return DeliveryNotesModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['number', 'status', 'customer_number', 'customer', 'issue_date', 'delivery_date', 'currency', 'total_net', 'total_tax', 'total', 'customer_reference'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = DeliveryNoteSearchReader::read($query->parameters(), $query->text(), $query->order(DeliveryNoteSearch::SORTS));

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $note) {
                yield $this->row($note, $company);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @return list<string> */
    private function row(DeliveryNote $note, Company $company): array
    {
        $totals = $this->totals->of($note);
        $snapshot = $note->getCustomerSnapshot();
        $customer = $note->getCustomer();
        $header = $note->getHeader();

        return [
            $note->getNumber() ?? '',
            $note->getStatus()->value,
            $snapshot->number ?? $customer->getNumber(),
            $snapshot->name ?? $customer->getProfile()->name,
            $note->getIssueDate()?->format('Y-m-d') ?? '',
            $header->deliveryDate?->format('Y-m-d') ?? '',
            $company->getCurrency(),
            $totals->subtotalNet,
            $totals->totalTax,
            $totals->total,
            $header->customerReference ?? '',
        ];
    }
}
