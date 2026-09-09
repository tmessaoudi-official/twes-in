<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** The password appears in a known breach corpus, so it is refused before an account is made from it. */
final class PasswordBreached extends \DomainException
{
}
