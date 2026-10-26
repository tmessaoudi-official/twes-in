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
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Domain\Exception\InvalidContact;
use App\Module\Customers\Infrastructure\ApiPlatform\Resource\ContactResource;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ContactResource, ContactResource> */
final readonly class AddContactProcessor implements ProcessorInterface
{
    public function __construct(private ManageContacts $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ContactResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $contact = $this->manage->add($company, CompanyPath::identifier($uriVariables, 'customerId'), $data->details(), $data->isPrimary, $this->guard->account()->getId());
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        } catch (InvalidContact $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return ContactResource::of($contact);
    }
}
