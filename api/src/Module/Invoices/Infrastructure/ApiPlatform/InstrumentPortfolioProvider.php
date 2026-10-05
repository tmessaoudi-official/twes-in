<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\ManageInstruments;
use App\Module\Invoices\Domain\InstrumentPortfolioSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of the company's cheques and traites, narrowed by status and sorted in the database (docs/SPEC.md § 7, lists
 * at scale).
 *
 * @implements ProviderInterface<InstrumentPortfolioRowResource>
 */
final readonly class InstrumentPortfolioProvider implements ProviderInterface
{
    public function __construct(private ManageInstruments $instruments, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<InstrumentPortfolioRowResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        $search = InstrumentPortfolioSearchReader::read(Paging::parameters($context), Paging::order($operation, InstrumentPortfolioSearch::SORTS));

        return $this->paging->paginator(
            $this->instruments->portfolio($company, $search, $this->paging->request($operation, $context)),
            InstrumentPortfolioRowResource::of(...),
        );
    }
}
