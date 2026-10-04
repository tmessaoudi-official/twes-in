<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** Whoever edits a role may add to it only what they hold themselves: an owner holds everything, so is never refused. */
final class PermissionNotHeld extends \DomainException
{
}
