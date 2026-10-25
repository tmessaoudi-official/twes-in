<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

/** Removing someone is not leaving: a person never takes themselves out of a company, so a slip never cuts their own access. */
final class OwnMembership extends \DomainException
{
}
