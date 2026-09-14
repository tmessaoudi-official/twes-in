<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Pdf;

use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Shared\Application\PdfRenderingFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stores a note's PDF as soon as it is validated. The validation is already committed: a renderer that cannot answer
 * is logged, and the note's first download renders and stores the PDF instead.
 */
#[AsEventListener(event: DeliveryNoteValidated::class)]
final readonly class StoreIssuedDeliveryNotePdf
{
    public function __construct(private PrintDeliveryNote $print, private LoggerInterface $logger)
    {
    }

    public function __invoke(DeliveryNoteValidated $event): void
    {
        try {
            $this->print->storeIssued($event->companyId, $event->deliveryNoteId);
        } catch (PdfRenderingFailed $failure) {
            $this->logger->warning('The PDF of delivery note {number} was not stored at validation; its first download stores it: {reason}', [
                'number' => $event->number,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
