<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Intl;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * A phone kept as E.164 as a person reads it: its own country's international grouping (« +216 71 900 868 »), whatever
 * the record's country, so that a form sending back what it was answered names the same number. A value kept before
 * the rule, which is not E.164, is answered as it is kept, and sent back unchanged it stays. The one formatter every
 * answer, printed document and mail goes through.
 */
final class GroupedPhones
{
    private const string E164 = '/^\+[1-9][0-9]{6,14}$/D';

    /** @return ($stored is null ? null : string) */
    public static function of(?string $stored): ?string
    {
        if (null === $stored || 1 !== preg_match(self::E164, $stored)) {
            return $stored;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->format($util->parse($stored, 'ZZ'), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $stored;
        }
    }
}
