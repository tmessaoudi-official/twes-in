<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Permission;

/**
 * Every permission a company's own role may grant (docs/SPEC.md § 7, 2026-09-20 11:30).
 *
 * This is a port rather than a service because of where the answer comes from: most of it is collected from the
 * module manifests, and the module registry already reads this context. Declaring the contract here and letting that
 * context implement it keeps the arrow pointing one way — the alternative, Tenancy reaching into ModuleRegistry, is
 * a cycle between two contexts that both know about companies.
 *
 * A platform permission is deliberately NOT here. It is held outside any membership (§ 3 Authorization), so offering
 * one to a company's role would be offering a company the operator's keys.
 */
interface KnownPermissions
{
    /** @return list<PermissionGroup> in the order a screen shows them */
    public function groups(): array;

    /** Whether a role may be given this string; false for anything invented, and false for the wildcard. */
    public function knows(string $permission): bool;
}
