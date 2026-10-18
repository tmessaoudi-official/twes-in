<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\ManagePurchaseOrders;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderSearch;
use App\Module\Purchases\Infrastructure\ApiPlatform\Output\PurchaseOrderView;
use App\Module\Purchases\Infrastructure\ApiPlatform\Payload\PurchaseOrderSearchReader;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * One page of a company's orders to its vendors, searched, narrowed and sorted in the database.
 *
 * @implements ProviderInterface<PurchaseOrderResource>
 */
final readonly class PurchaseOrderCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ManagePurchaseOrders $manage,
        private PurchaseOrderView $view,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<PurchaseOrderResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::READ);
        $search = PurchaseOrderSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, PurchaseOrderSearch::SORTS));

        $page = $this->manage->search($company, $search, $this->paging->request($operation, $context));

        return $this->paging->paginator(new Page($this->view->page($page->items), $page->total, $page->request), static fn (PurchaseOrderResource $row): PurchaseOrderResource => $row);
    }
}
