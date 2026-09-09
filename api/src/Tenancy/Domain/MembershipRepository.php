<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface MembershipRepository
{
    /** @return list<Membership> at most $limit of them, in no particular order */
    public function ofUser(Uuid $userId, int $limit): array;

    public function ofUserInCompany(Uuid $userId, Uuid $companyId): ?Membership;

    public function save(Membership $membership): void;
}
