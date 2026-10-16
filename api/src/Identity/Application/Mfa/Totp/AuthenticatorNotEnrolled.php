<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

/** The account has no authenticator in force: none was enrolled, it is still pending, or it was removed. */
final class AuthenticatorNotEnrolled extends \RuntimeException
{
}
