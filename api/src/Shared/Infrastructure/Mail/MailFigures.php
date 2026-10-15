<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Infrastructure\Twig\DecimalExtension;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The figures a mail writes, as its reader writes them: through the printed documents' canon (`DecimalExtension`, the
 * `pdf` domain's order of a day), with the reader's own date and number formats winning over their language. A value that
 * is not what it should be, such as one an older notice carried in another shape, is written as it was sent.
 */
final readonly class MailFigures
{
    private const string DECIMAL = '/^-?\d+(\.\d+)?$/';

    public function __construct(private DecimalExtension $decimals, private TranslatorInterface $translator)
    {
    }

    public function amount(string $value, int $scale, string $locale, string $numberFormat): string
    {
        return 1 === preg_match(self::DECIMAL, $value) ? $this->decimals->format($value, $scale, self::language($locale), $numberFormat) : $value;
    }

    /** A quantity with no trailing zero: 3.500 is 3.5, and 12.000 is 12, never a French « 12,000 ». */
    public function quantity(string $value, string $locale, string $numberFormat): string
    {
        return 1 === preg_match(self::DECIMAL, $value) ? $this->decimals->format($value, 0, self::language($locale), $numberFormat) : $value;
    }

    /** A day sent as `Y-m-d`. */
    public function day(string $value, string $locale, string $dateFormat): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return false === $day || $day->format('Y-m-d') !== $value ? $value : $day->format($this->pattern($locale, $dateFormat));
    }

    /** A moment sent as `Y-m-d H:i`, already in the time zone it is read in. */
    public function moment(string $value, string $locale, string $dateFormat): string
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone('UTC'));

        return false === $moment || $moment->format('Y-m-d H:i') !== $value ? $value : $moment->format($this->pattern($locale, $dateFormat).' H:i');
    }

    private function pattern(string $locale, string $dateFormat): string
    {
        return $this->decimals->datePattern($dateFormat, $this->translator->trans('format.date', [], 'pdf', $locale));
    }

    private static function language(string $locale): string
    {
        return strtok($locale, '_-') ?: $locale;
    }
}
