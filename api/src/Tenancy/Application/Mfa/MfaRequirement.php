<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Mfa;

use App\Identity\Domain\Model\User;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this user must carry a second factor.
 *
 * The question is asked of every company the user belongs to, not of the one currently chosen: a member of a
 * strict company and a relaxed one must enrol either way, or the company switcher would be the way around it
 * (ruling of 2026-09-10). A platform operator must carry one whatever their companies say (docs/SPEC.md § 7,
 * 2026-09-15, S3): the platform scope reaches every company's approval and every account. Where the installation is
 * reached from asks it of more roles (`Reach`), on the same terms.
 */
final readonly class MfaRequirement
{
    private Reach $reach;

    public function __construct(
        private MembershipRepository $memberships,
        #[Autowire(env: 'TAILNET_HOST')]
        string $tailnetHost,
        #[Autowire(env: 'PUBLIC_HOST')]
        string $publicHost,
    ) {
        $this->reach = Reach::of($tailnetHost, $publicHost);
    }

    public function appliesTo(User $user): bool
    {
        $roles = $this->reach->secondFactorRoles();

        return $user->isPlatformOperator()
            || null === $roles
            || $this->memberships->anyCompanyRequiresMfa($user->getId())
            || $this->memberships->holdsAnyRoleNamed($user->getId(), $roles);
    }

    /** Whether entering this company asks a second factor of this member: the company's own word, or their role's here. */
    public function inCompany(User $user, Company $company): bool
    {
        if ($company->isMfaRequired()) {
            return true;
        }
        $role = $this->memberships->ofUserInCompany($user->getId(), $company->getId())?->getRole();

        return null !== $role && $this->reach->asksSecondFactorOf($role->getName());
    }
}
