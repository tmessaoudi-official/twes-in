<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Something that happened to an aggregate and that other parts of the application may act on (docs/SPEC.md § 3 Domain
 * conventions). The aggregate records it; the use case publishes it once its transaction has committed, so nothing
 * ever acts on a change that was rolled back.
 */
interface DomainEvent
{
}
