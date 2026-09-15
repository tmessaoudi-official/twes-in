<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;

/** The companies as the platform's operators see them, across every tenant: only an operator's endpoint reaches this. */
final readonly class PlatformCompanies
{
    private const array STATUSES = [Company::STATUS_PENDING, Company::STATUS_ACTIVE, Company::STATUS_SUSPENDED];

    public function __construct(private CompanyRepository $companies, private MembershipRepository $memberships)
    {
    }

    /**
     * @return list<PlatformCompanyView> every company when no status is given, by name; otherwise those in it, oldest first
     *
     * @throws UnknownCompanyStatus
     */
    public function byStatus(?string $status): array
    {
        if (null !== $status && !\in_array($status, self::STATUSES, true)) {
            throw new UnknownCompanyStatus(\sprintf('status: expected %s.', implode(', ', self::STATUSES)));
        }
        $companies = null === $status ? $this->companies->all() : $this->companies->ofStatus($status);

        return array_map($this->viewOf(...), $companies);
    }

    public function viewOf(Company $company): PlatformCompanyView
    {
        $owners = [];
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER === $membership->getRole()->getName()) {
                $owners[] = $membership->getUser()->getEmail()->value;
            }
        }

        return new PlatformCompanyView(
            $company->getId()->toRfc4122(),
            $company->getName(),
            $company->getCountryCode(),
            $company->getStatus(),
            $company->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $owners,
        );
    }
}
