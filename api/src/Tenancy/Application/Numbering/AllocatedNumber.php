<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/** A number a document carries, and the company's day it was issued on (midnight UTC, the way a date column holds it). */
final readonly class AllocatedNumber
{
    public function __construct(public string $number, public \DateTimeImmutable $issueDate)
    {
    }
}
