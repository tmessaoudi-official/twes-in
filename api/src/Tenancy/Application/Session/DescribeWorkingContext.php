<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DescribeWorkingContext
{
    public function __construct(
        private MembershipRepository $memberships,
        private CurrentCompany $currentCompany,
        private CompanyLogo $logo,
        private SupportAccess $support,
        private KnownPermissions $permissions,
    ) {
    }

    /**
     * Null without a session company, or when the user is no longer a member of it nor reads it through a live grant;
     * through one, the role is support's and the permissions every read but what goods cost.
     */
    public function for(Uuid $userId): ?WorkingContext
    {
        $companyId = $this->currentCompany->id();
        if (null === $companyId) {
            return null;
        }
        $membership = $this->memberships->ofUserInCompany($userId, $companyId);
        if (null === $membership) {
            $grant = $this->support->grantOf($userId, $companyId);

            return null === $grant ? null : $this->described($grant->getCompany(), SupportAccess::ROLE, $this->supportReads(), $grant->getEndsAt());
        }

        return $this->described($membership->getCompany(), $membership->getRole()->getName(), $membership->getRole()->getPermissions(), $this->support->liveUntil($companyId));
    }

    /** @param list<string> $permissions */
    private function described(Company $company, string $role, array $permissions, ?\DateTimeImmutable $supportAccessUntil): WorkingContext
    {
        return new WorkingContext(
            $company->getId()->toRfc4122(),
            $company->getName(),
            $company->getCountryCode(),
            $company->getCurrency(),
            $company->getLocale(),
            $company->getTimezone(),
            $company->getStatus(),
            $role,
            $permissions,
            $this->logo->current($company)?->getFile()->getId()->toRfc4122(),
            $supportAccessUntil,
        );
    }

    /** @return list<string> */
    private function supportReads(): array
    {
        $reads = [];
        foreach ($this->permissions->groups() as $group) {
            foreach ($group->permissions as $permission) {
                if (SupportAccess::permits($permission)) {
                    $reads[] = $permission;
                }
            }
        }

        return array_values(array_unique($reads));
    }
}
