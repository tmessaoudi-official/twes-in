<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\CustomerNumberTaken;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Module\Customers\Infrastructure\ApiPlatform\Resource\CustomerResource;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<CustomerResource, CustomerResource> */
final readonly class ReviseCustomerProcessor implements ProcessorInterface
{
    public function __construct(private ManageCustomers $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $customer = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'customerId'), $data->input($company), $this->guard->account()->getId());
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        } catch (CustomerNumberTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidCustomer $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return CustomerResource::of($customer);
    }
}
