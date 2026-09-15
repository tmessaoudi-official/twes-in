<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/** An operator deactivating their own account would lock the platform out of its own screen. */
final class OwnAccount extends \DomainException
{
}
