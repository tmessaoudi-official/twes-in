<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceReceiverPickResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Shared\Application\Receivers\ReceiverContacts;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * The customer's contacts for the invoice's « Reçu par », under the invoice's own permission (see the resource beside
 * this); none for a customer of another company.
 *
 * @implements ProviderInterface<InvoiceReceiverPickResource>
 */
final readonly class InvoiceReceiverPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ReceiverContacts $contacts)
    {
    }

    /** @return list<InvoiceReceiverPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return array_map(InvoiceReceiverPickResource::of(...), $this->contacts->contactsOf($company, CompanyPath::identifier($uriVariables, 'customerId')));
    }
}
