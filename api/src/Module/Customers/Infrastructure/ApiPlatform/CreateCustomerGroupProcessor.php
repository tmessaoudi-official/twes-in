<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\CustomerGroupNameTaken;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Module\Customers\Domain\InvalidCustomerGroup;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<CustomerGroupResource, CustomerGroupResource> */
final readonly class CreateCustomerGroupProcessor implements ProcessorInterface
{
    public function __construct(private ManageCustomerGroups $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerGroupResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $group = $this->manage->create($company, $data->name, $data->description, $this->guard->account()->getId());
        } catch (CustomerGroupNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidCustomerGroup $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return CustomerGroupResource::of($group, 0);
    }
}
