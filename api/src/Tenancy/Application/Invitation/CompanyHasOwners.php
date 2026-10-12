<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** A company that still has an owner is given new ones by them, never from the platform. */
final class CompanyHasOwners extends \DomainException
{
}
