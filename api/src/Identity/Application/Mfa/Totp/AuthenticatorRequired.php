<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

/** The account must carry a second factor, so its authenticator is replaced, never removed, a passkey beside it or not. */
final class AuthenticatorRequired extends \RuntimeException
{
}
