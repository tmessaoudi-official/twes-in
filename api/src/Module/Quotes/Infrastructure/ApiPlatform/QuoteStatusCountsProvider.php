<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Quotes\Application\ManageQuotes;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<QuoteStatusCountsResource> */
final readonly class QuoteStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageQuotes $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QuoteStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);

        return QuoteStatusCountsResource::of($this->manage->statusCounts(
            $company,
            QuoteSearchReader::read(Paging::parameters($context), Paging::text($operation), [])->withoutStatus(),
        ));
    }
}
