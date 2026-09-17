<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Tells every open screen of the people concerned that something changed (docs/SPEC.md § 7, 2026-09-17). A change is
 * said when the unit of work of Transactions::run() that staged it commits, and never when it rolls back; one staged
 * outside such a unit (a sign-in, a refusal) is not a change to data and is dropped. The audit trail stages every row
 * it writes, so a use case says nothing more unless what it changes is not audited.
 */
interface LiveChanges
{
    public function stage(LiveChange $change): void;
}
