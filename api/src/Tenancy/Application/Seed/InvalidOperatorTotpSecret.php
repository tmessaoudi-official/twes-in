<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Seed;

/** An authenticator secret is base32; anything else would enrol a factor no authenticator can produce codes for. */
final class InvalidOperatorTotpSecret extends \DomainException
{
}
