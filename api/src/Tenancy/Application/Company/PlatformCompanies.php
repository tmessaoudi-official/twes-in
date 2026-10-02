<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\CompanySearch;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Symfony\Component\Uid\Uuid;

/** The companies as the platform's operators see them, across every tenant: only an operator's endpoint reaches this. */
final readonly class PlatformCompanies
{
    public function __construct(private CompanyRepository $companies, private MembershipRepository $memberships)
    {
    }

    /**
     * One page of the companies, narrowed by words, status and country and sorted in the database.
     *
     * @return Page<PlatformCompanyView>
     */
    public function search(CompanySearch $search, PageRequest $page): Page
    {
        $found = $this->companies->search($search, $page);
        $owners = [];
        foreach ($this->memberships->ownersOfCompanies(array_map(static fn (Company $company): Uuid => $company->getId(), $found->items)) as $membership) {
            $owners[$membership->getCompany()->getId()->toRfc4122()][] = $membership->getUser()->getEmail()->value;
        }

        return $found->map(fn (Company $company): PlatformCompanyView => $this->viewWith($company, $owners[$company->getId()->toRfc4122()] ?? []));
    }

    public function viewOf(Company $company): PlatformCompanyView
    {
        $owners = [];
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER === $membership->getRole()->getName()) {
                $owners[] = $membership->getUser()->getEmail()->value;
            }
        }

        return $this->viewWith($company, $owners);
    }

    /** @param list<string> $owners */
    private function viewWith(Company $company, array $owners): PlatformCompanyView
    {
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
