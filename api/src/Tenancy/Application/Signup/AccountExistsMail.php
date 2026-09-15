<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/** The answer mailed to an address that asked to sign up and already has an account: sign in instead. */
final readonly class AccountExistsMail
{
    public function __construct(
        public string $to,
        public string $loginUrl,
        public string $locale,
    ) {
    }
}
