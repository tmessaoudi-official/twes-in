<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** A link whose address has no account yet makes one, and an account needs a name and a password. */
final class AccountDetailsRequired extends \DomainException
{
}
