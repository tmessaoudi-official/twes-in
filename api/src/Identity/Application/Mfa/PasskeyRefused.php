<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** A passkey that does not verify, for whatever reason: the caller learns no more than that. */
final class PasskeyRefused extends \RuntimeException
{
}
