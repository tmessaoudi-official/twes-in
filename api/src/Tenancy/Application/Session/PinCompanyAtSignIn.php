<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * « Société à l'ouverture » (docs/SPEC.md § 7, 2026-09-25 09:03): the company every sign-in of this person opens, or
 * none, meaning the one they last worked in. A person's own choice, so it needs no permission beyond belonging.
 */
final readonly class PinCompanyAtSignIn
{
    /** More companies than one person plausibly works in, as the switcher reads them. */
    private const int LIMIT = 200;

    public function __construct(private MembershipRepository $memberships)
    {
    }

    /** @throws NotAMember */
    public function to(Uuid $userId, ?Uuid $companyId): void
    {
        if (null !== $companyId && null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        }

        // Unpin before pinning: the database keeps one pin per person, and each save is its own flush.
        $memberships = $this->memberships->ofUser($userId, self::LIMIT);
        foreach ([false, true] as $pinning) {
            foreach ($memberships as $membership) {
                $pinned = null !== $companyId && $membership->getCompany()->getId()->equals($companyId);
                if ($pinned === $pinning && $pinned !== $membership->isOpenedAtSignIn()) {
                    $membership->openAtSignIn($pinned);
                    $this->memberships->save($membership);
                }
            }
        }
    }
}
