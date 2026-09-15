<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/**
 * Everything the signup link mail needs. It states how long the link lasts rather than when it ends: the person has no
 * account and so no time zone yet, and "24 hours" needs none.
 */
final readonly class SignupLinkMail
{
    public function __construct(
        public string $to,
        public string $linkUrl,
        public string $locale,
        public int $validForHours,
    ) {
    }
}
