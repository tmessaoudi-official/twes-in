<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\ContactNotFound;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\ManageContacts;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ContactResource, null> */
final readonly class RemoveContactProcessor implements ProcessorInterface
{
    public function __construct(private ManageContacts $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $this->manage->remove($company, CompanyPath::identifier($uriVariables, 'customerId'), CompanyPath::identifier($uriVariables, 'contactId'), $this->guard->account()->getId());
        } catch (CustomerNotFound|ContactNotFound $absent) {
            throw new NotFoundHttpException('No such contact.', $absent);
        }

        return null;
    }
}
