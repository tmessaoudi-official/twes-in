<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteReceiverPickResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\Shared\Application\Receivers\ReceiverContacts;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * The customer's contacts for the quote's « Reçu par », under the quote's own permission (see the resource beside this);
 * none for a customer of another company.
 *
 * @implements ProviderInterface<QuoteReceiverPickResource>
 */
final readonly class QuoteReceiverPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ReceiverContacts $contacts)
    {
    }

    /** @return list<QuoteReceiverPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);

        return array_map(QuoteReceiverPickResource::of(...), $this->contacts->contactsOf($company, CompanyPath::identifier($uriVariables, 'customerId')));
    }
}
