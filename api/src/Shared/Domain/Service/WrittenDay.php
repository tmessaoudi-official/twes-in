<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

/**
 * A day, or a moment, written in a known format: `createFromFormat('!Y-m-d', '2026-13-45')` does not fail, it rolls into
 * the next year and answers a date, so what was typed, sent or imported is one only once it reads back as written. The
 * one place that parses such a text: `scripts/gates/day-parsing.sh` refuses `createFromFormat` anywhere else in `api/src`
 * unless its line says why.
 */
final class WrittenDay
{
    /**
     * The day written, at the start of its first unit not in the format (midnight for a day), in the zone given or the
     * default one; null when the text is not that format or names a day or a time the calendar lacks.
     */
    public static function parsed(string $written, string $format = 'Y-m-d', ?\DateTimeZone $zone = null): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!'.$format, $written, $zone);

        return false === $day || $day->format($format) !== $written ? null : $day;
    }
}
