<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Shared\Domain\ValueObject\Refusal;

/**
 * What a way to reach someone is held to wherever it is written: the form, a file, anything else that writes it. Each
 * check answers the refusal, its stable reason and that text's parameters, or null for a value it takes; whoever writes
 * the value stops at the first. Checked when a value is written, never when it is read back: a row stored before a rule
 * still reads (docs/SPEC.md § 7, 2026-10-09 10:40 (1) and 2026-10-15 20:00 Q12).
 */
final class ContactRules
{
    public const int EMAIL_MAX = 254;
    public const int WEBSITE_MAX = 200;

    /** ISO 3166-1 alpha-2, the codes in use. */
    public const array COUNTRIES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE',
        'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD',
        'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM',
        'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN',
        'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME',
        'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM',
        'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI',
        'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK',
        'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** The WHATWG HTML standard's valid e-mail address, its domain carrying at least one dot. */
    private const string EMAIL = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/D';
    /** A phone number as an HTML form's pattern writes it, the whole value matched. */
    public const string PHONE_PATTERN = '\+?[0-9 ().\-]{3,40}';
    private const string PHONE = '/^'.self::PHONE_PATTERN.'$/D';
    /**
     * A web page's address: http or https, optional credentials, a host with at least one dot (an address of numbers
     * alone is no host), an optional port, path, query and fragment.
     */
    private const string WEBSITE = '~^https?://(?:[_.\p{L}\p{N}-]+(?::[_.\p{L}\p{N}-]*)?@)?(?![0-9]{1,3}(?:\.[0-9]{1,3}){3}(?:[:/?#]|$))(?:[_\p{L}\p{N}](?:[_\p{L}\p{N}-]{0,61}[_\p{L}\p{N}])?\.)+[\p{L}\p{N}-]{1,63}\.?(?::[0-9]{1,5})?(?:[/?#][^\s]*)?$~iuD';
    /** ISO 9362: bank, country, place, and an optional branch; spaces between them are not part of it. */
    private const string BIC = '/^[A-Z]{4}([A-Z]{2})[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D';
    /** ISO 13616: country, check digits, then up to thirty letters or digits. */
    private const string IBAN = '/^([A-Z]{2})[0-9]{2}[A-Z0-9]{11,30}$/D';

    public static function email(string $value): ?Refusal
    {
        return \strlen($value) > self::EMAIL_MAX || 1 !== preg_match(self::EMAIL, $value)
            ? new Refusal('invalid_email', 'Not an e-mail address.')
            : null;
    }

    public static function phone(string $value): ?Refusal
    {
        return 1 === preg_match(self::PHONE, $value) ? null : new Refusal('invalid_phone', 'A phone number holds digits, spaces, dots, dashes and brackets, and may start with +.');
    }

    public static function website(string $value): ?Refusal
    {
        return mb_strlen($value) > self::WEBSITE_MAX || 1 !== preg_match(self::WEBSITE, $value)
            ? new Refusal('invalid_website', 'A web address starts with http:// or https:// and names a host.')
            : null;
    }

    public static function country(string $value): ?Refusal
    {
        return \in_array($value, self::COUNTRIES, true) ? null : new Refusal('invalid_country', 'Not a country code (ISO 3166-1 alpha-2).');
    }

    public static function bic(string $value): ?Refusal
    {
        return 1 === preg_match(self::BIC, strtoupper(str_replace(' ', '', $value)), $parts) && null === self::country($parts[1])
            ? null
            : new Refusal('invalid_bic', 'Not a BIC (ISO 9362).');
    }

    /** The shape and checksum the standard gives every IBAN; a country's own length is the form's to add. */
    public static function iban(string $value): ?Refusal
    {
        $iban = strtoupper(str_replace(' ', '', $value));
        if (1 !== preg_match(self::IBAN, $iban, $parts) || null !== self::country($parts[1]) || 1 !== self::mod97(substr($iban, 4).substr($iban, 0, 4))) {
            return new Refusal('invalid_iban', 'Not an IBAN (ISO 13616).');
        }

        return null;
    }

    public static function text(string $value, int $max): ?Refusal
    {
        return mb_strlen($value) > $max ? new Refusal('invalid_text', \sprintf('At most %d characters.', $max), ['max' => $max]) : null;
    }

    /** The remainder by 97 of the number the letters and digits spell, each letter as 10 to 35. */
    private static function mod97(string $rearranged): int
    {
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (\ord($character) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
