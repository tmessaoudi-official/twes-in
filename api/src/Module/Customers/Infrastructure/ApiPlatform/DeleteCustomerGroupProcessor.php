<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\CustomerGroupInUse;
use App\Module\Customers\Application\CustomerGroupNotFound;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<CustomerGroupResource, null> */
final readonly class DeleteCustomerGroupProcessor implements ProcessorInterface
{
    public function __construct(private ManageCustomerGroups $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $this->manage->delete($company, CompanyPath::identifier($uriVariables, 'groupId'), $this->guard->account()->getId());
        } catch (CustomerGroupNotFound $absent) {
            throw new NotFoundHttpException('No such customer group.', $absent);
        } catch (CustomerGroupInUse $inUse) {
            throw new ConflictHttpException($inUse->getMessage(), $inUse);
        }

        return null;
    }
}
