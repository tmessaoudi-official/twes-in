<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

/** A registration that verified: the credential id, base64url, and the record to verify its assertions against. */
final readonly class VerifiedPasskey
{
    public function __construct(
        public string $credentialId,
        public string $record,
    ) {
    }
}
