<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** A role somebody still holds is not deleted: the ruling is to refuse and name the holders, never to move people quietly (docs/SPEC.md § 7, 2026-09-20). */
final class RoleInUse extends \DomainException
{
}
