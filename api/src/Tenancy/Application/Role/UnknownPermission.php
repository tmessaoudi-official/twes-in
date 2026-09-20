<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** A role may only hold permissions the collected catalogue knows: never the owner's wildcard, never a platform one, and never a string no module declares. */
final class UnknownPermission extends \DomainException
{
}
