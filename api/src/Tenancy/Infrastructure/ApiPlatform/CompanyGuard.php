<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The one place a "{companyId}" in a path is turned into a company the caller may act on. A company that does
 * not exist and a company the caller has nothing to do with answer the same 404: a 403 would confirm the
 * identifier exists, which is how a tenant list gets enumerated.
 *
 * A platform operator passes without a membership. That is not a shortcut: an operator opens a company and
 * adds its first owner, and is by definition not a member of it yet (docs/SPEC.md § 3 Auth, onboarding).
 */
final readonly class CompanyGuard
{
    public function __construct(
        private Security $security,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
    ) {
    }

    /** @throws NotFoundHttpException when the company is absent or none of the caller's business */
    public function companyForActing(Uuid $companyId, string $permission): Company
    {
        $company = $this->companies->ofId($companyId);
        if (null === $company || !$this->may($company, $permission)) {
            throw new NotFoundHttpException('No such company.');
        }

        return $company;
    }

    /**
     * Whether the caller holds the permission in the company: what a response offers them. Enforcing is companyForActing's.
     * A company that is not active is closed to its members, pending an operator's approval or suspended by one, and
     * answers them as it answers a stranger; the session still describes it, which is how the application explains why.
     */
    public function may(Company $company, string $permission): bool
    {
        $account = $this->account();
        if ($account->isPlatformOperator()) {
            return true;
        }
        if (!$company->isActive()) {
            return false;
        }
        $role = $this->memberships->ofUserInCompany($account->getId(), $company->getId())?->getRole();

        return null !== $role && $role->grants($permission);
    }

    public function account(): SecurityUser
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // The operation security expression runs first; this is the type guard, not the access check.
            throw new AccessDeniedException();
        }

        return $account;
    }
}
