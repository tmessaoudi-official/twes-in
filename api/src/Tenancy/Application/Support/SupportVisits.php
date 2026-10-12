<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

use Symfony\Component\Uid\Uuid;

/** What the activity log already says of an operator's reading under « Accès support ». */
interface SupportVisits
{
    /** Whether this person's read of this page in this company is recorded since then. */
    public function readSince(Uuid $companyId, Uuid $userId, string $page, \DateTimeImmutable $since): bool;
}
