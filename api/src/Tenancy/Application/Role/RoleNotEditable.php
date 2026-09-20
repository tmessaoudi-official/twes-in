<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** A built-in role is defined by the release: a company reads it, never edits or deletes it. */
final class RoleNotEditable extends \DomainException
{
}
