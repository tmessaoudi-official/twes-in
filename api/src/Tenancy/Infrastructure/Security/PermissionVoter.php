<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\CompanyAccess;
use App\Shared\Application\CurrentCompany;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides every permission string (Tenancy\Domain\Permission): "platform.*" for platform operators only;
 * everything else through the user role in the company being acted on, the Company passed as subject, else
 * the session company. No company, no membership, or a role that lists neither the permission nor "*": denied.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly CurrentCompany $currentCompany,
        private readonly CompanyAccess $access,
    ) {
    }

    protected function supports(string $attribute, mixed $subject, ?Vote $vote = null): bool
    {
        return Permission::isWellFormed($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $account = $token->getUser();
        if (!$account instanceof SecurityUser) {
            return false;
        }
        $permission = Permission::fromString($attribute);
        if ($permission->isPlatformScoped()) {
            return $account->isPlatformOperator();
        }

        $companyId = $subject instanceof Company ? $subject->getId() : $this->currentCompany->id();
        if (null === $companyId) {
            return false;
        }

        $membership = $this->memberships->ofUserInCompany($account->getId(), $companyId);

        // A company that is not active grants its members nothing: pending an operator's approval, or suspended by one.
        return null !== $membership && $membership->getCompany()->isActive() && $membership->getRole()->grants($permission->value)
            // An unpaid subscription narrows what the role grants, judged on the permission asked for, never on "*".
            && $this->access->accessOf($companyId)->permits($permission->value);
    }
}
