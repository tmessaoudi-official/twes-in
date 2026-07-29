<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Money;

use Twes\Domain\Money\Exception\UnknownCurrency;

/**
 * An ISO 4217 currency, and — the only reason this class exists — its number of decimal places.
 *
 * twes-in's default currency is TND, one of seven ISO 4217 currencies with **three** decimal places
 * (1 dinar = 1000 millimes). So the usual shortcuts are all wrong here: there is no "cents", no
 * multiply-by-100 to reach minor units, and no two-decimal default. Scale is data, looked up per
 * currency, and an unrecognised code is refused rather than guessed at.
 *
 * The registry is deliberately curated rather than pulled from `intl`. ICU's currency data varies by
 * ICU version and by locale, and "how many decimals does this currency have" is a domain fact that
 * must give the same answer on every machine forever — not one that shifts under a library upgrade.
 */
final readonly class Currency
{
    /**
     * ISO 4217 alpha-3 code => number of minor-unit decimal places.
     *
     * Grouped by scale rather than alphabetically, because the groups are the point. Adding a
     * currency means adding a line here and nothing else; CurrencyTest pins the three-decimal group
     * as a complete set, so a wrong scale in that group fails the build.
     */
    private const array SCALES = [
        // ---- three decimals. The complete ISO 4217 set. TND is twes-in's default currency.
        'BHD' => 3, // Bahraini dinar
        'IQD' => 3, // Iraqi dinar
        'JOD' => 3, // Jordanian dinar
        'KWD' => 3, // Kuwaiti dinar
        'LYD' => 3, // Libyan dinar
        'OMR' => 3, // Omani rial
        'TND' => 3, // Tunisian dinar

        // ---- four decimals. The reason money columns are NUMERIC(19,4).
        'CLF' => 4, // Chilean unidad de fomento
        'UYW' => 4, // Uruguayan nominal wage index unit

        // ---- zero decimals. An amount with a fraction is meaningless in these.
        'BIF' => 0, // Burundian franc
        'CLP' => 0, // Chilean peso
        'DJF' => 0, // Djiboutian franc
        'GNF' => 0, // Guinean franc
        'ISK' => 0, // Icelandic krona
        'JPY' => 0, // Japanese yen
        'KMF' => 0, // Comorian franc
        'KRW' => 0, // South Korean won
        'PYG' => 0, // Paraguayan guarani
        'RWF' => 0, // Rwandan franc
        'UGX' => 0, // Ugandan shilling
        'UYI' => 0, // Uruguayan peso en unidades indexadas
        'VND' => 0, // Vietnamese dong
        'VUV' => 0, // Vanuatu vatu
        'XAF' => 0, // Central African CFA franc
        'XOF' => 0, // West African CFA franc
        'XPF' => 0, // CFP franc

        // ---- two decimals. The majority — listed explicitly so it is never a fallback.
        'AED' => 2, 'AFN' => 2, 'ALL' => 2, 'AMD' => 2, 'ANG' => 2, 'AOA' => 2,
        'ARS' => 2,
        'AUD' => 2, 'AWG' => 2, 'AZN' => 2, 'BAM' => 2, 'BBD' => 2, 'BDT' => 2,
        'BGN' => 2, 'BMD' => 2, 'BND' => 2, 'BOB' => 2, 'BRL' => 2, 'BSD' => 2,
        'BTN' => 2, 'BWP' => 2, 'BYN' => 2, 'BZD' => 2, 'CAD' => 2, 'CDF' => 2,
        'CHF' => 2, 'CNY' => 2, 'COP' => 2, 'CRC' => 2, 'CUP' => 2, 'CVE' => 2,
        'CZK' => 2, 'DKK' => 2, 'DOP' => 2, 'DZD' => 2, 'EGP' => 2, 'ERN' => 2,
        'ETB' => 2, 'EUR' => 2, 'FJD' => 2, 'FKP' => 2, 'GBP' => 2, 'GEL' => 2,
        'GHS' => 2, 'GIP' => 2, 'GMD' => 2, 'GTQ' => 2, 'GYD' => 2, 'HKD' => 2,
        'HNL' => 2, 'HTG' => 2, 'HUF' => 2, 'IDR' => 2, 'ILS' => 2, 'INR' => 2,
        'IRR' => 2, 'JMD' => 2, 'KES' => 2, 'KGS' => 2, 'KHR' => 2, 'KPW' => 2,
        'KYD' => 2, 'KZT' => 2, 'LAK' => 2, 'LBP' => 2, 'LKR' => 2, 'LRD' => 2,
        'LSL' => 2, 'MAD' => 2, 'MDL' => 2, 'MGA' => 2, 'MKD' => 2, 'MMK' => 2,
        'MNT' => 2, 'MOP' => 2, 'MRU' => 2, 'MUR' => 2, 'MVR' => 2, 'MWK' => 2,
        'MXN' => 2, 'MYR' => 2, 'MZN' => 2, 'NAD' => 2, 'NGN' => 2, 'NIO' => 2,
        'NOK' => 2, 'NPR' => 2, 'NZD' => 2, 'PAB' => 2, 'PEN' => 2, 'PGK' => 2,
        'PHP' => 2, 'PKR' => 2, 'PLN' => 2, 'QAR' => 2, 'RON' => 2, 'RSD' => 2,
        'RUB' => 2, 'SAR' => 2, 'SBD' => 2, 'SCR' => 2, 'SDG' => 2, 'SEK' => 2,
        'SGD' => 2, 'SHP' => 2, 'SLE' => 2, 'SOS' => 2, 'SRD' => 2, 'SSP' => 2,
        'STN' => 2, 'SVC' => 2, 'SYP' => 2, 'SZL' => 2, 'THB' => 2, 'TJS' => 2,
        'TMT' => 2, 'TOP' => 2, 'TRY' => 2, 'TTD' => 2, 'TWD' => 2, 'TZS' => 2,
        'UAH' => 2, 'USD' => 2, 'UYU' => 2, 'UZS' => 2, 'VED' => 2, 'VES' => 2,
        'WST' => 2, 'XCD' => 2, 'XCG' => 2, 'YER' => 2, 'ZAR' => 2, 'ZMW' => 2,
        'ZWG' => 2,
    ];

    private function __construct(
        private string $code,
        private int $scale,
    ) {}

    /**
     * @throws UnknownCurrency if the code is malformed or not in the registry
     */
    public static function of(string $code): self
    {
        // Registry membership is the whole validation: every key is a well-formed alpha-3 code, so
        // anything malformed — padded, numeric, wrong length — simply is not in it.
        $normalised = strtoupper($code);

        if (!isset(self::SCALES[$normalised])) {
            throw UnknownCurrency::code($code);
        }

        return new self($normalised, self::SCALES[$normalised]);
    }

    /**
     * Every registered currency code.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::SCALES);
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * Number of decimal places this currency's amounts are expressed in — 3 for TND, 2 for EUR,
     * 0 for JPY.
     */
    public function scale(): int
    {
        return $this->scale;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
