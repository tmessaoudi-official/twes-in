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
use App\Tenancy\Application\Role\RoleInUse;
use App\Tenancy\Application\Role\RoleNotEditable;
use App\Tenancy\Application\Role\RoleNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<RoleResource, null> */
final readonly class DeleteRoleProcessor implements ProcessorInterface
{
    public function __construct(private ManageRoles $roles, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        try {
            $this->roles->delete($company, CompanyPath::identifier($uriVariables, 'roleId'), $this->guard->account()->getId());
        } catch (RoleNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (RoleNotEditable $refused) {
            throw new UnprocessableEntityHttpException(self::refusal(RoleResource::BUILT_IN, $refused), $refused);
        } catch (RoleInUse $refused) {
            throw new UnprocessableEntityHttpException(self::refusal(RoleResource::IN_USE, $refused), $refused);
        }

        return null;
    }

    /** A stable token first, then the sentence: the screen reads the token, a person reads the rest. */
    private static function refusal(string $code, \Throwable $refused): string
    {
        return \sprintf('%s: %s', $code, $refused->getMessage());
    }
}
