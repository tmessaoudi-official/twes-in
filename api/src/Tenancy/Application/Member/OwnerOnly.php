<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

/** Closing a company down, or taking it back, is its owner's alone, whatever a custom role grants. */
final class OwnerOnly extends \DomainException
{
}
