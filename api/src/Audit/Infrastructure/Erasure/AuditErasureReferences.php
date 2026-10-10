<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasureReference;

/** The activity journal is always kept: what was done stays written, to rows an erasure took since as well. */
final readonly class AuditErasureReferences implements DeclaresErasure
{
    public function steps(): array
    {
        return [];
    }

    public function references(): array
    {
        return [ErasureReference::ignore('audit_log', 'entity_id', null, 'The journal keeps what was done, to rows erased since as well.')];
    }

    public function files(): array
    {
        return [];
    }
}
