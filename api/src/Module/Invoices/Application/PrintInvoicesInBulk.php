<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The invoices a person selected, printed. Each is the PDF its own page gives, so an issued one is the file stored when
 * it was issued, and the same check that previewed the selection decides which go in: a draft or a cancelled one only
 * when asked for, an id no longer found never.
 */
final readonly class PrintInvoicesInBulk
{
    public function __construct(private CheckInvoicesInBulk $check, private PrintInvoice $print, private PdfRenderer $renderer)
    {
    }

    /**
     * Each invoice's own PDF, in the order chosen.
     *
     * @param list<Uuid> $ids
     *
     * @return non-empty-list<PrintedInvoice>
     *
     * @throws NothingToPrint
     * @throws PdfRenderingFailed
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function each(Company $company, array $ids, bool $drafts, bool $cancelled): array
    {
        $printed = [];
        foreach ($this->check->check($company, BulkAction::Pdf, $ids, $drafts, $cancelled) as $row) {
            if (!$row->included()) {
                continue;
            }
            try {
                $printed[] = $this->print->pdf($company, $row->id);
            } catch (InvoiceNotFound) {
                // Deleted between the check and its printing: as absent as one the check did not find.
            }
        }

        return [] === $printed ? throw new NothingToPrint() : $printed;
    }

    /**
     * The same, as one PDF.
     *
     * @param list<Uuid> $ids
     *
     * @throws NothingToPrint
     * @throws PdfRenderingFailed
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function merged(Company $company, array $ids, bool $drafts, bool $cancelled): string
    {
        return $this->renderer->merge(array_map(static fn (PrintedInvoice $printed): string => $printed->contents, $this->each($company, $ids, $drafts, $cancelled)));
    }
}
