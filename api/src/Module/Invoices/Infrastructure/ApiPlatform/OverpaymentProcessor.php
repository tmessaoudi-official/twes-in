<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageCustomerCredit;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Keeps what was paid beyond an invoice to its customer's credit, a movement of that customer's balance, which takes
 * `customer.read` to read.
 *
 * @implements ProcessorInterface<OverpaymentResource, null>
 */
final readonly class OverpaymentProcessor implements ProcessorInterface
{
    public function __construct(private CompanyGuard $guard, private ManageCustomerCredit $credit)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $company = $this->guard->companyForActing($companyId, InvoicePermission::PAYMENT_WRITE);

        try {
            $this->credit->overpayment($company, CompanyPath::identifier($uriVariables, 'invoiceId'), $data->details(), $this->guard->account()->getId());
        } catch (InvoiceNotFound|CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return null;
    }
}
