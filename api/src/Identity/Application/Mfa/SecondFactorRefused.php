<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** The code did not verify: wrong, expired, already spent, or there was no factor to check it against. */
final class SecondFactorRefused extends \DomainException
{
}
