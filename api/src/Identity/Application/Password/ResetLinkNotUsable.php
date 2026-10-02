<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

/** A reset link that is unknown, malformed, expired or already used: the same answer for each, so none can be told apart. */
final class ResetLinkNotUsable extends \DomainException
{
}
