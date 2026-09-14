<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Module\Invoices\Application\PrintInvoice;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Shared\Application\PdfRenderingFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stores a document's PDF as soon as it is issued. The issue is already committed: a renderer that cannot answer is
 * logged, and the document's first download renders and stores the PDF instead.
 */
#[AsEventListener(event: InvoiceIssued::class)]
final readonly class StoreIssuedInvoicePdf
{
    public function __construct(private PrintInvoice $print, private LoggerInterface $logger)
    {
    }

    public function __invoke(InvoiceIssued $event): void
    {
        try {
            $this->print->storeIssued($event->companyId, $event->invoiceId);
        } catch (PdfRenderingFailed $failure) {
            $this->logger->warning('The PDF of invoice {number} was not stored at issue; its first download stores it: {reason}', [
                'number' => $event->number,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
