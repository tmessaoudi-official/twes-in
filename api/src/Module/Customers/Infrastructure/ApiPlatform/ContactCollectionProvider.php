<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Domain\Contact;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ContactResource> */
final readonly class ContactCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageContacts $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ContactResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);

        try {
            return array_map(static fn (Contact $contact) => ContactResource::of($contact), $this->manage->list($company, CompanyPath::identifier($uriVariables, 'customerId')));
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        }
    }
}
