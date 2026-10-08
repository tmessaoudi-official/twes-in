<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** A day a request wrote, read as a calendar day; one that does not exist is refused rather than rolled over. */
final class RequestedDay
{
    public static function of(string $field, string $day): \DateTimeImmutable
    {
        $read = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if (false === $read || $read->format('Y-m-d') !== $day) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s is not a day.', $field, $day));
        }

        return $read;
    }

    public static function orNull(string $field, ?string $day): ?\DateTimeImmutable
    {
        return null === $day ? null : self::of($field, $day);
    }
}
