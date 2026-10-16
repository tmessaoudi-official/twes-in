<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\Payment\ManageInstruments;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\PaymentInstrumentResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<PaymentInstrumentResource> */
final readonly class InvoiceInstrumentsProvider implements ProviderInterface
{
    public function __construct(private ManageInstruments $instruments, private CompanyGuard $guard)
    {
    }

    /** @return list<PaymentInstrumentResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        try {
            $found = $this->instruments->ofInvoice($company, CompanyPath::identifier($uriVariables, 'invoiceId'));
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        }

        return array_map(PaymentInstrumentResource::of(...), $found);
    }
}
