<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Application\Closing\ClosingTerms;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DescribeWorkingContext
{
    public function __construct(
        private MembershipRepository $memberships,
        private CurrentCompany $currentCompany,
        private CompanyLogo $logo,
        private SupportAccess $support,
        private KnownPermissions $permissions,
        private ClosingTerms $closing,
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

            return null === $grant ? null : $this->described($grant->getCompany(), SupportAccess::ROLE, $this->supportReads(), $grant->getEndsAt(), $grant->getReason());
        }

        $company = $membership->getCompany();
        $role = $membership->getRole();
        // A closed company is still read, never worked in: the session offers what the voter still grants there.
        $permissions = $company->isClosedDown()
            ? array_values(array_filter($this->known(), static fn (string $permission): bool => $role->grants($permission) && $company->permitsWhileClosedDown($permission)))
            : $role->getPermissions();

        return $this->described($company, $role->getName(), $permissions, $this->support->liveUntil($companyId));
    }

    /** @param list<string> $permissions */
    private function described(Company $company, string $role, array $permissions, ?\DateTimeImmutable $supportAccessUntil, ?string $supportAccessReason = null): WorkingContext
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
            $this->closing->of($company),
            $supportAccessReason,
            Company::STATUS_SUSPENDED === $company->getStatus() && !$company->wasOpened(),
            $company->getSuspensionReason(),
        );
    }

    /** @return list<string> */
    private function supportReads(): array
    {
        return array_values(array_filter($this->known(), SupportAccess::permits(...)));
    }

    /** @return list<string> every permission the application knows, once */
    private function known(): array
    {
        $known = [];
        foreach ($this->permissions->groups() as $group) {
            foreach ($group->permissions as $permission) {
                $known[] = $permission;
            }
        }

        return array_values(array_unique($known));
    }
}
