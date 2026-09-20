<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Role;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A company's own roles (docs/SPEC.md § 7, 2026-09-20 11:30, row 104): listing what it may use, and creating,
 * revising and deleting the ones it made for itself.
 *
 * Two rules sit here rather than on the screen, because the API is what enforces them. A role may hold only
 * permissions the **collected** catalogue knows, so a string no module declares — a typo, or a permission a later
 * release dropped — cannot sit in a role for ever granting nothing and showing nowhere. And a role somebody still
 * holds is refused rather than deleted, naming who holds it: `membership.role_id` is NOT NULL with no ON DELETE,
 * so the alternative is a foreign-key error, and the alternative to *that* is quietly moving people to another
 * role, which is a demotion or a promotion nobody would notice (developer ruling, 2026-09-20).
 *
 * Every write is audited, and the row carries the name and the permissions rather than just the verb: a role is
 * what decides who may do what, so "revised" alone cannot answer the question an audit is read to answer. The audit
 * row is also how an open screen hears the change — `DoctrineAuditTrail` stages each entry as a live change whose
 * kind is the entity type, which is the `role` the roles page listens for (docs/SPEC.md § 7, 2026-09-17).
 */
final readonly class ManageRoles
{
    public const string ENTITY_TYPE = 'role';
    public const string CREATED = 'role.created';
    public const string REVISED = 'role.revised';
    public const string DELETED = 'role.deleted';

    /** Enough names for a refusal to be useful without becoming a directory of the company. */
    private const int NAMED_HOLDERS = 5;

    public function __construct(
        private RoleRepository $roles,
        private MembershipRepository $memberships,
        private KnownPermissions $permissions,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /** @return list<RoleView> */
    public function list(Company $company): array
    {
        $counts = $this->memberships->countByRole($company->getId());

        return array_map(
            static fn (Role $role): RoleView => new RoleView(
                $role->getId()->toRfc4122(),
                $role->getName(),
                $role->isBuiltIn(),
                array_values(array_filter($role->getPermissions(), static fn (string $p): bool => Permission::WILDCARD !== $p)),
                \in_array(Permission::WILDCARD, $role->getPermissions(), true),
                $counts[$role->getId()->toRfc4122()] ?? 0,
            ),
            $this->roles->forCompany($company->getId()),
        );
    }

    /**
     * @param list<string> $permissions
     *
     * @throws RoleNameTaken
     * @throws UnknownPermission
     */
    public function create(Company $company, string $name, array $permissions, ?Uuid $actorUserId): RoleView
    {
        return $this->transactions->run(function () use ($company, $name, $permissions, $actorUserId): RoleView {
            $name = $this->cleanName($company, $name);
            $this->assertKnown($permissions);

            $role = new Role($name, $permissions, $company);
            $this->roles->save($role);
            $this->record($company, $role, self::CREATED, $actorUserId);

            return new RoleView($role->getId()->toRfc4122(), $role->getName(), false, $role->getPermissions(), false, 0);
        });
    }

    /**
     * @param list<string> $permissions
     *
     * @throws RoleNotFound
     * @throws RoleNotEditable
     * @throws RoleNameTaken
     * @throws UnknownPermission
     */
    public function revise(Company $company, Uuid $roleId, string $name, array $permissions, ?Uuid $actorUserId): RoleView
    {
        return $this->transactions->run(function () use ($company, $roleId, $name, $permissions, $actorUserId): RoleView {
            $role = $this->editable($company, $roleId);
            $name = $this->cleanName($company, $name, $roleId);
            $this->assertKnown($permissions);

            $role->rename($name);
            $role->redefine($permissions);
            $this->roles->save($role);
            $this->record($company, $role, self::REVISED, $actorUserId);

            return new RoleView(
                $role->getId()->toRfc4122(),
                $role->getName(),
                false,
                $role->getPermissions(),
                false,
                $this->memberships->countByRole($company->getId())[$role->getId()->toRfc4122()] ?? 0,
            );
        });
    }

    /**
     * @throws RoleNotFound
     * @throws RoleNotEditable
     * @throws RoleInUse
     */
    public function delete(Company $company, Uuid $roleId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $roleId, $actorUserId): void {
            $role = $this->editable($company, $roleId);

            $holders = $this->memberships->holdersOfRole($company->getId(), $roleId, self::NAMED_HOLDERS + 1);
            if ([] !== $holders) {
                $named = \array_slice($holders, 0, self::NAMED_HOLDERS);
                $more = \count($holders) > self::NAMED_HOLDERS ? ' and others' : '';
                throw new RoleInUse(\sprintf('The %s role is held by %s%s. Move them to another role before deleting it.', $role->getName(), implode(', ', $named), $more));
            }

            // Recorded before the row goes: afterwards the name and the permissions exist nowhere else, and they are
            // the whole content of the question "what could somebody with this role do?".
            $this->record($company, $role, self::DELETED, $actorUserId);
            $this->roles->remove($role);
        });
    }

    private function record(Company $company, Role $role, string $action, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $role->getId(),
            $action,
            $actorUserId,
            ['name' => $role->getName(), 'permissions' => $role->getPermissions()],
            $company->getId(),
        ));
    }

    /**
     * @throws RoleNotFound
     * @throws RoleNotEditable
     */
    private function editable(Company $company, Uuid $roleId): Role
    {
        $role = $this->roles->ofIdForCompany($roleId, $company->getId());
        if (null === $role) {
            throw new RoleNotFound('No such role.');
        }
        if ($role->isBuiltIn()) {
            throw new RoleNotEditable(\sprintf('The %s role is defined by the release and cannot be changed.', $role->getName()));
        }

        return $role;
    }

    /** @throws RoleNameTaken */
    private function cleanName(Company $company, string $name, ?Uuid $except = null): string
    {
        $name = trim($name);
        if ($this->roles->nameIsTaken($company->getId(), $name, $except)) {
            throw new RoleNameTaken(\sprintf('This company already has a role named "%s".', $name));
        }

        return $name;
    }

    /**
     * @param list<string> $permissions
     *
     * @throws UnknownPermission
     */
    private function assertKnown(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->permissions->knows($permission)) {
                throw new UnknownPermission(\sprintf('"%s" is not a permission a role may be given.', $permission));
            }
        }
    }
}
