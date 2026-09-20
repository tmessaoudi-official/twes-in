<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Role\ManageRoles;
use App\Tenancy\Application\Role\RoleNameTaken;
use App\Tenancy\Application\Role\RoleNotEditable;
use App\Tenancy\Application\Role\RoleNotFound;
use App\Tenancy\Application\Role\UnknownPermission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<RoleResource, RoleResource> */
final readonly class ReviseRoleProcessor implements ProcessorInterface
{
    public function __construct(private ManageRoles $roles, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RoleResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        try {
            return RoleResource::of($this->roles->revise(
                $company,
                CompanyPath::identifier($uriVariables, 'roleId'),
                $data->name,
                $data->permissions,
            ));
        } catch (RoleNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (RoleNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (RoleNotEditable $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', RoleResource::BUILT_IN, $refused->getMessage()), $refused);
        } catch (UnknownPermission $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', RoleResource::UNKNOWN_PERMISSION, $refused->getMessage()), $refused);
        }
    }
}
