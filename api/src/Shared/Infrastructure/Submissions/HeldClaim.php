<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use Symfony\Component\Uid\Uuid;

/**
 * The claim one request won: its account's key, and the attempt naming this request, so that what it marks, answers or
 * frees is its own claim and never one another request took back from it once it had run too long.
 */
final readonly class HeldClaim
{
    public function __construct(
        public Uuid $account,
        public Uuid $key,
        public Uuid $attempt,
    ) {
    }
}
