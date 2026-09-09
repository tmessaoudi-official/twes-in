<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DescribeWorkingContext
{
    public function __construct(private MembershipRepository $memberships, private CurrentCompany $currentCompany)
    {
    }

    /** Null without a session company, or when the user is no longer a member of it. */
    public function for(Uuid $userId): ?WorkingContext
    {
        $companyId = $this->currentCompany->id();
        $membership = null === $companyId ? null : $this->memberships->ofUserInCompany($userId, $companyId);
        if (null === $membership) {
            return null;
        }
        $company = $membership->getCompany();

        return new WorkingContext(
            $company->getId()->toRfc4122(),
            $company->getName(),
            $company->getCountryCode(),
            $company->getCurrency(),
            $company->getLocale(),
            $company->getTimezone(),
            $company->getStatus(),
            $membership->getRole()->getName(),
            $membership->getRole()->getPermissions(),
        );
    }
}
