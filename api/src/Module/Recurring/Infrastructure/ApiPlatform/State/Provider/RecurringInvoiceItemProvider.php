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
use App\Module\Recurring\Application\RecurringInvoiceNotFound;
use App\Module\Recurring\Infrastructure\ApiPlatform\Resource\RecurringInvoiceResource;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<RecurringInvoiceResource> */
final readonly class RecurringInvoiceItemProvider implements ProviderInterface
{
    public function __construct(private ManageRecurringInvoices $manage, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RecurringInvoiceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        try {
            $recurring = $this->manage->get($company, CompanyPath::identifier($uriVariables, 'recurringInvoiceId'));
        } catch (RecurringInvoiceNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return RecurringInvoiceResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelInvoiceId()->toRfc4122()] ?? null);
    }
}
