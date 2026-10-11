<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Invoices\Domain\InvoiceRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a bulk action would do with each invoice a person selected, before it runs, as every bulk action is previewed:
 * each id once, in the order chosen, included or skipped with its reason, so that nothing is left out without being
 * named. Another company's invoice reads as not found, as it does everywhere else.
 */
final readonly class CheckInvoicesInBulk
{
    /** A selection is one list page at most, and the largest page holds this many rows. */
    public const int MOST = 100;

    public function __construct(private InvoiceRepository $invoices)
    {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<BulkCheckRow>
     */
    public function check(Company $company, BulkAction $action, array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->toRfc4122()] ??= $id;
        }
        if ([] === $unique || \count($unique) > self::MOST) {
            throw new \InvalidArgumentException(\sprintf('A bulk action takes 1 to %d invoices.', self::MOST));
        }
        $found = [];
        foreach ($this->invoices->ofIdsInCompany(array_values($unique), $company->getId()) as $invoice) {
            $found[$invoice->getId()->toRfc4122()] = $invoice;
        }

        $rows = [];
        foreach ($unique as $key => $id) {
            $invoice = $found[$key] ?? null;
            $rows[] = null === $invoice
                ? new BulkCheckRow($id, null, BulkCheckRow::NOT_FOUND)
                : new BulkCheckRow($id, $invoice, match ($action) {
                    BulkAction::Export => null,
                });
        }

        return $rows;
    }
}
