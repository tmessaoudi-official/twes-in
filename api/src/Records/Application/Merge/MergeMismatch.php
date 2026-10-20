<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** Records holding different values where they must agree (two registration numbers) are two records, not one. */
final class MergeMismatch extends \RuntimeException
{
    public function __construct(public readonly string $column)
    {
        parent::__construct(\sprintf('The records hold different values in %s: they are not one record.', $column));
    }
}
