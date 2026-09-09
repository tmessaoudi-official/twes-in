<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** No user holds that address yet. The caller turns this into an invitation (G1b, second half). */
final class UserNotFound extends \DomainException
{
}
