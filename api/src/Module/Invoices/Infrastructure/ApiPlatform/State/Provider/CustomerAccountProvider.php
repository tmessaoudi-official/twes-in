<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Module\Invoices\Application\Credit\ReadCustomerAccount;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\CustomerAccountResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<CustomerAccountResource> */
final readonly class CustomerAccountProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ReadCustomerAccount $account)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerAccountResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // The account is the statement's money as it stands today: it takes the same two rights.
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $company = $this->guard->companyForActing($companyId, InvoicePermission::READ);

        try {
            return CustomerAccountResource::of($this->account->handle($company, CompanyPath::identifier($uriVariables, 'customerId')));
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        }
    }
}
