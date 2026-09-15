<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/** A finish the platform cannot honour: a country with no fiscal preset, a time zone that does not exist. */
final class InvalidSignup extends \DomainException
{
}
