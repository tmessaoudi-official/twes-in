<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/** A part « Effacer des données » does not offer. */
final class UnknownErasurePart extends \DomainException
{
    public function __construct(public readonly string $part)
    {
        parent::__construct(\sprintf('No part of the data is called "%s".', $part));
    }
}
