<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Shared\Domain\PrintSettings;
use App\Tenancy\Domain\Company;

/**
 * How a printed document writes its days and figures (docs/SPEC.md § 7, 2026-09-25 12:45, row 130): the company's own
 * `presentation.date-format` and `presentation.number-format`, since a printed document is the company's and never the
 * person's who printed it. `auto` leaves both to the document's language.
 */
final readonly class DocumentFormats
{
    /** @return array{dateFormat: string, numberFormat: string} */
    public static function of(ReadSetting $settings, Company $company): array
    {
        $context = new SettingContext($company);
        $date = $settings->value($context, 'presentation.date-format');
        $number = $settings->value($context, 'presentation.number-format');

        return ['dateFormat' => \is_string($date) ? $date : 'auto', 'numberFormat' => \is_string($number) ? $number : 'auto'];
    }

    /**
     * What a document prints with today: the notes its customer's settings say, and the company's formats. Issuing keeps
     * it with the document; a draft reads it again on every render.
     */
    public static function print(ReadSetting $settings, SettingContext $atCustomer, Company $company): PrintSettings
    {
        $notes = $settings->value($atCustomer, 'document.printed_notes');
        $formats = self::of($settings, $company);

        return new PrintSettings(\is_string($notes) ? $notes : '', $formats['dateFormat'], $formats['numberFormat']);
    }
}
