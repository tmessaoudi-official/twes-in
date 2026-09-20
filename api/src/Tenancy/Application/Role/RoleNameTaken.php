<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** Two roles a company may use may not share a name, the built-in three included. */
final class RoleNameTaken extends \DomainException
{
}
