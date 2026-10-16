<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Infrastructure\ApiPlatform\Resource\RecurringInvoiceResource;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<RecurringInvoiceResource> */
final readonly class RecurringInvoiceCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageRecurringInvoices $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<RecurringInvoiceResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        $recurring = $this->manage->list($company);
        $models = $this->manage->models($company, $recurring);

        return array_map(static fn ($one): RecurringInvoiceResource => RecurringInvoiceResource::of($one, $models[$one->getModelInvoiceId()->toRfc4122()] ?? null), $recurring);
    }
}
