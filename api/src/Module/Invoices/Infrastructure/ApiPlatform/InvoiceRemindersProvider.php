<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ReadInvoiceReminders;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<InvoiceReminderResource> */
final readonly class InvoiceRemindersProvider implements ProviderInterface
{
    public function __construct(private ReadInvoiceReminders $reminders, private CompanyGuard $guard)
    {
    }

    /** @return list<InvoiceReminderResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        try {
            return array_map(InvoiceReminderResource::of(...), $this->reminders->handle($company, CompanyPath::identifier($uriVariables, 'invoiceId')));
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        }
    }
}
