<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\ValueObject\PhoneVerdict;

/**
 * Phone numbers by their country's numbering plan: the one judge of every phone a person writes, and the one way a kept
 * number is shown and printed.
 */
interface PhoneNumbers
{
    /**
     * @param string      $typed   as the person wrote it, groups, brackets and the marks a paste brings included
     * @param string|null $country the record's country (ISO 3166-1 alpha-2), which reads a national number; a leading
     *                             « + » or « 00 » names another
     */
    public function judge(string $typed, ?string $country): PhoneVerdict;

    /** A kept number grouped as its country writes it; a value that is no number is given back as it is. */
    public function grouped(string $stored): string;
}
