<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Module\Invoices\Application\LateFeeWording;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The late fee's line as a printed document says it: `translations/pdf.<language>.yaml`, `invoice.late_fee_line`. */
final readonly class TranslatorLateFeeWording implements LateFeeWording
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function lateFeeLine(string $language, string $invoiceNumber, int $stage, int $daysLate): string
    {
        return $this->translator->trans('invoice.late_fee_line', ['%number%' => $invoiceNumber, '%stage%' => $stage, '%days%' => $daysLate], 'pdf', $language);
    }
}
