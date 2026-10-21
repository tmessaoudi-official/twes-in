<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\CustomerCheckScopes;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Infrastructure\ApiPlatform\Resource\CustomerResource;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<CustomerResource> */
final readonly class CustomerItemProvider implements ProviderInterface
{
    public function __construct(private ManageCustomers $manage, private CompanyGuard $guard, private CustomerCheckScopes $checkScopes)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);

        try {
            return CustomerResource::of($this->manage->get($company, CompanyPath::identifier($uriVariables, 'customerId')), $this->checkScopes->of($company));
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        }
    }
}
