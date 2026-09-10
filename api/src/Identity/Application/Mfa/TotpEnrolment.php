<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** What step one of enrolment hands to the person doing it: the secret to type, and the URI to scan. */
final readonly class TotpEnrolment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
    ) {
    }
}
