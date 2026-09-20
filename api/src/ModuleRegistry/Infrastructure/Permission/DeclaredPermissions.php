<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\Permission;

use App\Fiscal\Infrastructure\ApiPlatform\FiscalPermission;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Infrastructure\ApiPlatform\ModulePermission;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Permission\PermissionGroup;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;

/**
 * The catalogue, collected from the modules that declare themselves and completed by the few permissions that belong
 * to no module (docs/SPEC.md § 7, 2026-09-20 11:30).
 *
 * A module's permissions are already on its manifest beside its dependencies, so a module added tomorrow brings its
 * own heading and needs nothing added here. The three groups below are the remainder: a company's settings, its
 * members and its fiscal setup are not modules — none of them can be switched off — yet a role has to be able to
 * grant them, so they are named once.
 *
 * Naming them by hand is the weak point, and it is covered deliberately rather than argued away:
 * `PermissionCatalogueTest` discovers every permission constant in `src/` from the source and reds when one is not
 * here, so a tenth permission class cannot arrive unnoticed and be silently ungrantable.
 *
 * Every module is offered whether or not the company has it switched on. A role outlives a module being turned off
 * and on again, and a person editing one should not find a tick has vanished because someone else changed a switch —
 * the module's own 404 guard is what stops a permission being *used* while the module is off.
 */
final readonly class DeclaredPermissions implements KnownPermissions
{
    /** What a role may grant outside any module, in the order the screen reads them. */
    private const array UNMODULED = [
        'company' => [ModulePermission::READ, ModulePermission::WRITE],
        'members' => [MemberPermission::READ, MemberPermission::WRITE],
        'fiscal' => [FiscalPermission::READ, FiscalPermission::WRITE],
    ];

    public function __construct(private ModuleCatalog $modules)
    {
    }

    public function groups(): array
    {
        $groups = [];
        foreach (self::UNMODULED as $key => $permissions) {
            $groups[] = new PermissionGroup($key, "permissions.groups.$key", $permissions);
        }
        foreach ($this->modules->all() as $manifest) {
            if ([] !== $manifest->permissions) {
                $groups[] = new PermissionGroup($manifest->key, $manifest->labelKey, $manifest->permissions);
            }
        }

        return $groups;
    }

    public function knows(string $permission): bool
    {
        foreach ($this->groups() as $group) {
            if (\in_array($permission, $group->permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
