<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Quotes\Application\QuoteCustomers;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteCustomerPickResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * A few customers for the quote form's picker, under the quote's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<QuoteCustomerPickResource>
 */
final readonly class QuoteCustomerPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private QuoteCustomers $customers)
    {
    }

    /** @return list<QuoteCustomerPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);

        $ids = Paging::uuids($operation, 'ids');

        return array_map(QuoteCustomerPickResource::of(...), [] === $ids
            ? $this->customers->matching($company, Paging::text($operation) ?? '')
            : $this->customers->byIds($company, $ids));
    }
}
