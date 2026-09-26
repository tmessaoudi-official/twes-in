<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

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
}
