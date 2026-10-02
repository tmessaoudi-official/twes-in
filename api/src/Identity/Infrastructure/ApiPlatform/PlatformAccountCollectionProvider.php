<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Identity\Application\Account\AccountView;
use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Domain\AccountSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Application\Company\CompaniesOfAccounts;
use Symfony\Component\Uid\Uuid;

/**
 * One page of the accounts, searched, narrowed and sorted in the database, each with the companies it belongs to read
 * once for the page. The query parameters are declared on the operation, which checks them before this runs.
 *
 * @implements ProviderInterface<PlatformAccountResource>
 */
final readonly class PlatformAccountCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageAccounts $accounts, private CompaniesOfAccounts $companies, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<PlatformAccountResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $active = Paging::value($operation, 'active');
        $operator = Paging::value($operation, 'platformOperator');
        $search = new AccountSearch(Paging::text($operation), \is_bool($active) ? $active : null, \is_bool($operator) ? $operator : null, Paging::order($operation, AccountSearch::SORTS));
        $page = $this->accounts->search($search, $this->paging->request($operation, $context));
        $companies = $this->companies->of(array_map(static fn (AccountView $view): Uuid => Uuid::fromString($view->id), $page->items));

        return $this->paging->paginator($page, static fn (AccountView $view): PlatformAccountResource => PlatformAccountResource::of($view, $companies[$view->id] ?? []));
    }
}
