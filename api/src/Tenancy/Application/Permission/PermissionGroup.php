<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Permission;

/**
 * One heading of the roles screen and the permissions under it (docs/SPEC.md § 7, 2026-09-20 11:30).
 *
 * A group is how a person reads a matrix of twenty ticks: by the part of the product each belongs to. A module is one
 * group and carries the module's own label, so a module switched on brings its heading with it and needs nothing
 * added here.
 */
final readonly class PermissionGroup
{
    /**
     * @param string       $key         the module key, or the name of a part of the product that is not a module
     * @param string       $labelKey    the translation key the screen shows as the heading
     * @param list<string> $permissions in the order the screen offers them, read before write
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $permissions,
    ) {
    }
}
