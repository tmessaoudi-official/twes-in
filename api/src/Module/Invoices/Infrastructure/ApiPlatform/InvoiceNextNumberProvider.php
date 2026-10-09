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
use App\Module\Invoices\Application\NextInvoiceNumber;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<InvoiceNextNumberResource> */
final readonly class InvoiceNextNumberProvider implements ProviderInterface
{
    public function __construct(private NextInvoiceNumber $next, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceNextNumberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        try {
            $preview = $this->next->of($company, CompanyPath::identifier($uriVariables, 'invoiceId'));
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceNotDraft|NoNumberingSeries|InvalidNumbering $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }
        $resource = new InvoiceNextNumberResource();
        $resource->number = $preview->number;
        $resource->operationCategory = $preview->operations?->value;

        return $resource;
    }
}
