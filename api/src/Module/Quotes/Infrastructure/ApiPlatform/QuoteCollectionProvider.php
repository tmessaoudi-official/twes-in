<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Domain\QuoteSearch;
use App\Shared\Domain\Page;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of a company's quotes, searched, narrowed and sorted in the database. Each row's figures are worked out for
 * the page alone, so what the list costs does not grow with the ledger.
 *
 * @implements ProviderInterface<QuoteResource>
 */
final readonly class QuoteCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageQuotes $manage, private QuoteView $view, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<QuoteResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);
        $search = QuoteSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, QuoteSearch::SORTS));
        $page = $this->manage->search($company, $search, $this->paging->request($operation, $context));

        return $this->paging->paginator(new Page($this->view->page($page->items), $page->total, $page->request), static fn (QuoteResource $row): QuoteResource => $row);
    }
}
