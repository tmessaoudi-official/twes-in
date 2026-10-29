<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/** The platform lifts the password lock of a company's owner only: anyone else's is lifted by an owner of their company. */
final class NotAnOwner extends \DomainException
{
}
