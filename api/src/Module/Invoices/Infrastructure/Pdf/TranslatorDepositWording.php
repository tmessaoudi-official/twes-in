<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Application\DepositWording;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The words of deposit lines as a printed document says them: `translations/pdf.<language>.yaml`, `invoice.*`. */
final readonly class TranslatorDepositWording implements DepositWording
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function depositLine(string $language, string $quoteNumber, ?string $percentage): string
    {
        if (null === $percentage) {
            return $this->translator->trans('invoice.deposit_line', ['%number%' => $quoteNumber], 'pdf', $language);
        }
        // 30, 12.5 or 12,5 as the language writes a decimal, never 30.000.
        $share = rtrim(rtrim(Decimal::format(Decimal::of($percentage), 3), '0'), '.');

        return $this->translator->trans('invoice.deposit_share_line', ['%share%' => 'fr' === $language ? str_replace('.', ',', $share) : $share, '%number%' => $quoteNumber], 'pdf', $language);
    }

    public function deductionLine(string $language, string $depositNumber, \DateTimeImmutable $issueDate): string
    {
        $format = $this->translator->trans('format.date', [], 'pdf', $language);

        return $this->translator->trans('invoice.deduction_line', ['%number%' => $depositNumber, '%date%' => $issueDate->format($format)], 'pdf', $language);
    }
}
