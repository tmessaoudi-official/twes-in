<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use Symfony\Component\Uid\Uuid;

/** Another erasure may still be undone: the company has one waiting at a time, and it is that one the banner offers. */
final class ErasurePending extends \DomainException
{
    public function __construct(public readonly Uuid $erasureId)
    {
        parent::__construct('An erasure is waiting for its end; undo it or wait for it before erasing again.');
    }
}
