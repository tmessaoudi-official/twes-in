<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

/** Why a proof of entry was not taken, which the screen says beside it. */
final class EntryProofRefused extends \DomainException
{
    /** The password is not the account's. */
    public const string PASSWORD = 'invalid_credentials';
    /** The authenticator code the company asks beside the password is missing, wrong or spent. */
    public const string CODE = 'invalid_code';
    /** The passkey did not verify, or is not one of the account's. */
    public const string PASSKEY = 'invalid_passkey';
    /** The company does not take a passkey, or the account holds none. */
    public const string PASSKEY_NOT_TAKEN = 'passkey_sign_in_off';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
