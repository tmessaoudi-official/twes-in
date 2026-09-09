<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** The user has no membership in that company, so there is nothing to remove and nowhere to switch to. */
final class NotAMember extends \DomainException
{
}
