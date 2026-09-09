<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\Email;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adds someone who already has an account to a company, with no acceptance step: they proved that address
 * when their account was created, and the company simply appears in their switcher (docs/SPEC.md § 7,
 * 2026-09-09). An address with no user is refused here and becomes an invitation instead.
 */
final readonly class AddMember
{
    public const string ENTITY_TYPE = 'membership';
    public const string ADDED = 'membership.added';

    public function __construct(
        private CompanyRepository $companies,
        private UserRepository $users,
        private MembershipRepository $memberships,
        private RoleRepository $roles,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @throws CompanyNotFound|UserNotFound|UnknownRole|AlreadyAMember */
    public function handle(AddMemberRequest $request, ?Uuid $actorUserId): Membership
    {
        $company = $this->companies->ofId($request->companyId)
            ?? throw new CompanyNotFound(\sprintf('No company %s.', $request->companyId->toRfc4122()));

        $role = $this->roles->builtIn($request->roleName)
            ?? throw new UnknownRole(\sprintf('"%s" is not a built-in role.', $request->roleName));

        $user = $this->users->ofEmail(Email::fromString($request->email))
            ?? throw new UserNotFound(\sprintf('No user holds %s.', $request->email));

        if (null !== $this->memberships->ofUserInCompany($user->getId(), $company->getId())) {
            throw new AlreadyAMember(\sprintf('%s already belongs to %s.', $request->email, $company->getName()));
        }

        $now = $this->clock->now();
        $membership = new Membership($user, $company, $role, $now);
        $this->memberships->save($membership);

        // A pending company is one an operator opened and nobody owns yet: its first owner makes it usable.
        if (Role::OWNER === $role->getName()) {
            $company->activate($now);
            $this->companies->save($company);
        }

        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $membership->getId(),
            self::ADDED,
            $actorUserId,
            ['user_id' => $user->getId()->toRfc4122(), 'email' => $user->getEmail()->value, 'role' => $role->getName()],
            $company->getId(),
        ));

        return $membership;
    }
}
