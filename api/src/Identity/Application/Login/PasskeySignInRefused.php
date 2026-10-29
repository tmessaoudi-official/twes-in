<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

/** Why an account cannot be let sign in with a passkey alone, named so its owner's screen says what to do first. */
final class PasskeySignInRefused extends \DomainException
{
    /** The installation's operators keep it off for everyone. */
    public const string OFF = 'passkey_sign_in_off';
    /** The authenticator app is the way back from a lost passkey: none, no passkey sign-in. */
    public const string NEEDS_AUTHENTICATOR = 'passkey_sign_in_needs_authenticator';
    /** No passkey of the account lives on its device, so none could be found without an address typed first. */
    public const string NEEDS_PASSKEY = 'passkey_sign_in_needs_passkey';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
