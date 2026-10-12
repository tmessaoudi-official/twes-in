<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Symfony\Component\Uid\Uuid;

/**
 * An operator gives an owner to a company the platform opened, or to one whose owners are all gone, and to no other:
 * operators keep no standing access to a company's data, so a company that still has an owner gets new owners from
 * them alone, through their own invitations.
 */
final readonly class InviteOwnerFromPlatform
{
    public function __construct(
        private InviteToCompany $invite,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
    ) {
    }

    /** @throws CompanyNotFound|CompanyHasOwners|\App\Tenancy\Application\Company\AlreadyAMember|\App\Tenancy\Application\Company\RoleNotManageable */
    public function handle(Uuid $companyId, string $email, Uuid $operatorId): InviteOutcome
    {
        $company = $this->companies->ofId($companyId)
            ?? throw new CompanyNotFound(\sprintf('No company %s.', $companyId->toRfc4122()));
        if (Company::STATUS_PENDING !== $company->getStatus() && [] !== $this->memberships->ownersOfCompanies([$companyId])) {
            throw new CompanyHasOwners(\sprintf('%s has an owner, who invites the next ones.', $company->getName()));
        }

        return $this->invite->handle(new InviteRequest($companyId, $email, Role::OWNER), $operatorId);
    }
}
