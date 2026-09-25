<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's expenses, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 * How many files rest on a row is counted for the page alone, so what the list costs does not grow with the ledger.
 *
 * @implements ProviderInterface<ExpenseResource>
 */
final readonly class ExpenseCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ManageExpenses $manage,
        private CurrencyScales $scales,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<ExpenseResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);
        $scale = $this->scales->of($company->getCurrency());
        $status = Paging::value($operation, 'status');
        $search = new ExpenseSearch(
            Paging::text($operation),
            \is_string($status) ? ExpenseStatus::from($status) : null,
            self::identifier($operation, 'vendorId'),
            self::identifier($operation, 'categoryId'),
            Paging::order($operation, ExpenseSearch::SORTS),
        );

        $page = $this->manage->search($company, $search, $this->paging->request($operation, $context));
        $attached = $this->manage->attachmentCounts($company, $page->items);

        return $this->paging->paginator(
            $page,
            static fn (Expense $expense) => ExpenseResource::of($expense, $scale, $attached[$expense->getId()->toRfc4122()] ?? 0),
        );
    }

    /** A parameter naming a row of another table; its `uuid` format has already refused anything else, with a 422. */
    private static function identifier(Operation $operation, string $key): ?Uuid
    {
        $value = Paging::value($operation, $key);

        return \is_string($value) ? Uuid::fromString($value) : null;
    }
}
