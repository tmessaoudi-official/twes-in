<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Mfa;

use App\Tenancy\Domain\Model\Role;

/**
 * Where an installation is reached from, which says whose password alone is too little (docs/SPEC.md § 7, 2026-10-31
 * 21:14, Q5): on the shop's network alone a second factor is offered to everyone; through its tailnet, from a staff
 * device anywhere, owners and admins must carry one; from the public internet, everyone must.
 */
enum Reach: string
{
    case Network = 'network';
    case Tailnet = 'tailnet';
    case Public = 'public';

    /** The installation's outside names, each empty while that way in is off; the widest one given decides. */
    public static function of(string $tailnetHost, string $publicHost): self
    {
        return match (true) {
            '' !== trim($publicHost) => self::Public,
            '' !== trim($tailnetHost) => self::Tailnet,
            default => self::Network,
        };
    }

    /**
     * The roles whose holders must carry a second factor here, by name; null for every account, a member of no company
     * included.
     *
     * @return list<string>|null
     */
    public function secondFactorRoles(): ?array
    {
        return match ($this) {
            self::Network => [],
            self::Tailnet => [Role::OWNER, Role::ADMIN],
            self::Public => null,
        };
    }

    public function asksSecondFactorOf(string $roleName): bool
    {
        $roles = $this->secondFactorRoles();

        return null === $roles || \in_array($roleName, $roles, true);
    }
}
