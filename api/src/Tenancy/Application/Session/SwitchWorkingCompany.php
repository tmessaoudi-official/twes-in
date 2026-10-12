<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The switcher. The membership is checked here and not by the caller: the session company decides what every
 * later request may read, so an unchecked identifier would be a cross-company hole. An operator may also switch to a
 * company whose owner lets support read it, which the activity log records as support coming in.
 */
final readonly class SwitchWorkingCompany
{
    public function __construct(
        private MembershipRepository $memberships,
        private CurrentCompany $currentCompany,
        private ClockInterface $clock,
        private SupportAccess $support,
        private RecordSupportVisit $visits,
    ) {
    }

    /** @throws NotAMember */
    public function to(Uuid $userId, Uuid $companyId): void
    {
        $membership = $this->memberships->ofUserInCompany($userId, $companyId);
        if (null === $membership) {
            $grant = $this->support->grantOf($userId, $companyId)
                ?? throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
            $this->currentCompany->set($companyId);
            $this->visits->entered($grant, $userId);

            return;
        }

        $this->currentCompany->set($companyId);
        // The next sign-in reopens it (docs/SPEC.md § 7, 2026-09-25 09:03).
        $membership->use($this->clock->now());
        $this->memberships->save($membership);
    }
}
