<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

/** A role of another company answers as one that does not exist: the guard's rule, so a refusal names nothing. */
final class RoleNotFound extends \DomainException
{
}
