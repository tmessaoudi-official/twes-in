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
     * How many members of this company hold each role, keyed by the role's RFC 4122 identifier. A role nobody
     * holds is absent rather than zero; read it with a `?? 0`.
     *
     * One query for the whole list: asking per role would be one query per row of the roles screen.
     *
     * @return array<string, int>
     */
    public function countByRole(Uuid $companyId): array;

    /**
     * The addresses of the members of this company holding that role, at most $limit of them, oldest first.
     * A refusal names people rather than counting them, so someone reading it knows whom to move.
     *
     * @return list<string>
     */
    public function holdersOfRole(Uuid $companyId, Uuid $roleId, int $limit): array;

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
