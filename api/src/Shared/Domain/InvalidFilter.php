<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** A list was asked to narrow itself by something that is no value of that filter: a status it has not, a day that is not one. */
final class InvalidFilter extends \InvalidArgumentException
{
    public function __construct(public readonly string $filter, string $reason)
    {
        parent::__construct(\sprintf('%s: %s', $filter, $reason));
    }
}
