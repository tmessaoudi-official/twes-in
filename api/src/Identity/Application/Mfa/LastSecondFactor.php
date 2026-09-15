<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** Removing it would leave an account that a company requires to have a second factor with none. */
final class LastSecondFactor extends \RuntimeException
{
}
