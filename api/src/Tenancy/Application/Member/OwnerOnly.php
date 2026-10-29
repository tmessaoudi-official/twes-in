<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

/** What the company's owner alone does, whatever a custom role grants: closing it down or taking it back, opening it to support, lifting the lock on a member's password. */
final class OwnerOnly extends \DomainException
{
}
