<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Application\RecurringInvoiceNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<RecurringInvoiceResource, null> */
final readonly class DeleteRecurringInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private ManageRecurringInvoices $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);

        try {
            $this->manage->delete($company, CompanyPath::identifier($uriVariables, 'recurringInvoiceId'), $this->guard->account()->getId());
        } catch (RecurringInvoiceNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
