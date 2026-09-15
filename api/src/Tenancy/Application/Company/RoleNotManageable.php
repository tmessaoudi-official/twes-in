<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** The acting member's role does not reach the role they tried to grant or the member they tried to remove. */
final class RoleNotManageable extends \DomainException
{
}
