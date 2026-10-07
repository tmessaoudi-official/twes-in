<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Licensing\Application\CompanyStandings;
use App\Shared\Domain\ListFilters;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Application\Company\PlatformCompanies;
use App\Tenancy\Application\Company\PlatformCompanyView;
use App\Tenancy\Domain\CompanySearch;
use Symfony\Component\Uid\Uuid;

/**
 * One page of the companies, searched, narrowed and sorted in the database. The query parameters are declared on the
 * operation, which checks them before this runs.
 *
 * @implements ProviderInterface<PlatformCompanyResource>
 */
final readonly class PlatformCompanyCollectionProvider implements ProviderInterface
{
    public function __construct(private PlatformCompanies $companies, private CompanyStandings $standings, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<PlatformCompanyResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $filters = new ListFilters(Paging::parameters($context));
        $search = new CompanySearch(
            Paging::text($operation),
            $filters->choices('status', CompanySearch::STATUSES),
            $filters->matching('countryCode', '/^[A-Z]{2}$/'),
            Paging::order($operation, CompanySearch::SORTS),
        );
        $page = $this->companies->search($search, $this->paging->request($operation, $context));
        $standings = $this->standings->ofCompanies(array_map(static fn (PlatformCompanyView $view): Uuid => Uuid::fromString($view->id), $page->items));

        return $this->paging->paginator($page, static fn (PlatformCompanyView $view): PlatformCompanyResource => PlatformCompanyResource::of($view, $standings[$view->id] ?? null));
    }
}
