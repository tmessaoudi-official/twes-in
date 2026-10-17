<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use Symfony\Component\Uid\Uuid;

/** The sandbox a visitor took: the company to open and the account to sign in as. */
final readonly class ClaimedSandbox
{
    public function __construct(
        public Uuid $sandboxId,
        public Uuid $companyId,
        public Uuid $ownerId,
    ) {
    }
}
