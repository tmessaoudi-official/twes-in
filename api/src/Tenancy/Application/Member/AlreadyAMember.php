<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

/** One membership per (user, company); adding a second is refused rather than silently ignored. */
final class AlreadyAMember extends \DomainException
{
}
