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

    /** @return list<Membership> every member of one company, oldest first */
    public function ofCompany(Uuid $companyId): array;

    public function ofUserInCompany(Uuid $userId, Uuid $companyId): ?Membership;

    /**
     * Whether any company this user belongs to insists on a second factor.
     *
     * A predicate rather than a walk over `ofUser()`, which takes a limit: a cap that quietly stopped short
     * would answer "no second factor needed" for a user who does need one.
     */
    public function anyCompanyRequiresMfa(Uuid $userId): bool;

    public function save(Membership $membership): void;

    public function remove(Membership $membership): void;
}
