<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/** A signup link that cannot be used, for whichever reason: they are one answer, so the page is not an oracle. */
final class SignupNotUsable extends \DomainException
{
}
