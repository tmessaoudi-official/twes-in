<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Application\InstrumentNotFound;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInstruments;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<PaymentInstrumentResource, null> */
final readonly class DeleteInstrumentProcessor implements ProcessorInterface
{
    public function __construct(private ManageInstruments $instruments, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::PAYMENT_WRITE);

        try {
            $this->instruments->delete($company, CompanyPath::identifier($uriVariables, 'invoiceId'), CompanyPath::identifier($uriVariables, 'instrumentId'), $this->guard->account()->getId());
        } catch (InvoiceNotFound|InstrumentNotFound $absent) {
            throw new NotFoundHttpException('No such instrument.', $absent);
        } catch (InvoiceTransitionRefused $kept) {
            throw new ConflictHttpException($kept->getMessage(), $kept);
        }

        return null;
    }
}
