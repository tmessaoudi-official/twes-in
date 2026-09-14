<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\DomainEvent;

/**
 * In-process delivery of domain events (docs/SPEC.md § 3 Domain conventions): no message bus. A use case publishes what
 * its aggregates recorded once its transaction has committed, and every listener runs before the request ends.
 */
interface DomainEvents
{
    public function publish(DomainEvent ...$events): void;
}
