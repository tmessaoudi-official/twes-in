<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Seed;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;

/**
 * The built-in roles, the first operator and the first company, idempotently: run on an empty database and
 * again after a migration it converges on the same rows. A company is unusable without an owner role, so the
 * roles are seeded here too.
 */
final readonly class SeedPlatform
{
    /** @var array<string, list<string>> the three built-in roles and their permission sets */
    public const array BUILT_IN_ROLES = [
        Role::OWNER => [Permission::WILDCARD],
        Role::ADMIN => ['company.read', 'company.settings', 'user.read', 'user.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'customer.read', 'customer.write', 'product.read', 'product.write'],
        Role::MEMBER => ['company.read', 'invoice.read', 'invoice.write', 'customer.read', 'customer.write', 'product.read'],
    ];

    public function __construct(
        private RoleRepository $roles,
        private UserRepository $users,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private PasswordHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> what was created, in order; empty when every row already existed
     *
     * @throws OperatorPasswordRequired
     */
    public function seed(SeedRequest $request): array
    {
        $now = $this->clock->now();
        $email = Email::fromString($request->operatorEmail);
        $operator = $this->users->ofEmail($email);
        if (null === $operator && (null === $request->operatorPassword || '' === $request->operatorPassword)) {
            throw new OperatorPasswordRequired('--operator-password is required to create the operator; none is built in.');
        }

        $created = [];
        foreach (self::BUILT_IN_ROLES as $name => $permissions) {
            if (null === $this->roles->builtIn($name)) {
                $this->roles->save(new Role($name, $permissions, null, $now));
                $created[] = "role $name";
            }
        }

        if (null === $operator) {
            $operator = new User($email, $request->operatorName, $request->locale, $now);
            $operator->setPasswordHash($this->hasher->hash((string) $request->operatorPassword), $now);
            $operator->setPlatformOperator(true);
            $this->users->save($operator);
            $created[] = "operator $email";
        }

        $company = $this->companies->ofName($request->companyName);
        if (null === $company) {
            $company = new Company($request->companyName, $request->country, $request->currency, $request->locale, $request->timezone, $now);
            $this->companies->save($company);
            $created[] = "company {$request->companyName}";
        }

        if (null === $this->memberships->ofUserInCompany($operator->getId(), $company->getId())) {
            $owner = $this->roles->builtIn(Role::OWNER) ?? throw new \LogicException('The owner role was seeded a moment ago.');
            $this->memberships->save(new Membership($operator, $company, $owner, $now));
            $created[] = "membership $email owns {$request->companyName}";
        }

        return $created;
    }
}
