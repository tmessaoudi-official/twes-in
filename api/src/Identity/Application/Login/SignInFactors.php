<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

/** What a sign-in was proved with, which decides whether it also proves the company it opens. */
enum SignInFactors: string
{
    /** The password, for an account with no second factor. */
    case Password = 'password';
    /** The password, then the authenticator code, a recovery code or a passkey. */
    case PasswordAndFactor = 'password_and_factor';
    /** A passkey alone, with user verification. */
    case Passkey = 'passkey';
}
