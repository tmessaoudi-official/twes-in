<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Repository;

use App\Tenancy\Domain\Model\Invitation;
use Symfony\Component\Uid\Uuid;

interface InvitationRepository
{
    public function ofId(Uuid $id): ?Invitation;

    /** Looked up by hash: the raw token is never stored, so it is never compared. */
    public function ofTokenHash(string $tokenHash): ?Invitation;

    /** The invitation still open for that address in that company, if any. */
    public function pendingFor(Uuid $companyId, string $email): ?Invitation;

    /** @return list<Invitation> the company's invitations nobody has used and that have not expired, oldest first */
    public function openOfCompany(Uuid $companyId, \DateTimeImmutable $now): array;

    /**
     * @return list<Invitation> the company's invitations nobody has used, the open ones and those expired since
     *                          $expiredSince, oldest first
     */
    public function unusedOfCompany(Uuid $companyId, \DateTimeImmutable $expiredSince): array;

    /**
     * The same for several companies in one read, as the platform's list of companies asks for a page of them.
     *
     * @param list<Uuid> $companyIds
     *
     * @return list<Invitation>
     */
    public function unusedOfCompanies(array $companyIds, \DateTimeImmutable $expiredSince): array;

    /**
     * @return list<Invitation> of every company, the invitations nobody used that ran out by $now and whose sender was not
     *                          yet told, the earliest run out first, at most $limit
     */
    public function runOutUntold(\DateTimeImmutable $now, int $limit): array;

    public function save(Invitation $invitation): void;

    /** Inviting the same address again replaces the open invitation, so exactly one token ever works. */
    public function remove(Invitation $invitation): void;
}
