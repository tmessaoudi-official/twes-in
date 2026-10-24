<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

/** The mail telling an account's owner what changed in how it signs in, and what to do if it was not them. */
final readonly class SecurityNoticeMail
{
    public function __construct(
        public string $to,
        public string $locale,
        public SecurityChange $change,
        public ?string $passkeyName = null,
    ) {
    }
}
