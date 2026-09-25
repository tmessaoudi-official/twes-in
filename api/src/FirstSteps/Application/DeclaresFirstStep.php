<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\FirstSteps\Application;

use App\Tenancy\Domain\Company;

/**
 * One step of « Premiers pas » (docs/SPEC.md § 7, 2026-09-25 22:17, row 139), declared by the context that owns what it
 * checks, the way a module declares its settings and what to watch. Whether it is done is worked out from what is
 * there on every read — a customer exists, the registration number is filled in — never ticked by hand, so a step
 * undone (the only customer deleted) comes back by itself.
 */
interface DeclaresFirstStep
{
    /** Stable, lowercase, dotted: what the screen translates and links from (`customers.first`). */
    public function key(): string;

    /** Its place in the approved order (design direction § 4.2); lower first. */
    public function position(): int;

    /** The key of the module it belongs to, or null for a step of the core, shown whatever is switched on. */
    public function module(): ?string;

    /** The permission that DOES the step: a member who may not create a customer is not asked to. */
    public function permission(): string;

    public function isDone(Company $company): bool;
}
