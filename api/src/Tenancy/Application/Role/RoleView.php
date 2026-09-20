<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** One role a company may use, as the roles screen shows it. */
final readonly class RoleView
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public string $name,
        public bool $builtIn,
        public array $permissions,
        public bool $wildcard,
        public int $memberCount,
    ) {
    }
}
