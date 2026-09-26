<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<ExpenseStatusCountsResource> */
final readonly class ExpenseStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageExpenses $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        return ExpenseStatusCountsResource::of($this->manage->statusCounts($company, new ExpenseSearch(
            Paging::text($operation),
            null,
            self::identifier($operation, 'vendorId'),
            self::identifier($operation, 'categoryId'),
        )));
    }

    /** A parameter naming a row of another table; its `uuid` format has already refused anything else, with a 422. */
    private static function identifier(Operation $operation, string $key): ?Uuid
    {
        $value = Paging::value($operation, $key);

        return \is_string($value) ? Uuid::fromString($value) : null;
    }
}
