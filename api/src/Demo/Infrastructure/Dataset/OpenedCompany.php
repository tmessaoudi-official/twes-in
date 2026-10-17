<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Dataset;

use Symfony\Component\Uid\Uuid;

/** The company a demo history is written into, and the member every step of it is written as. */
final readonly class OpenedCompany
{
    public function __construct(
        public Uuid $companyId,
        public Uuid $actorId,
    ) {
    }
}
