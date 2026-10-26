<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceTransitionRefused;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\PaymentResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<PaymentResource, PaymentResource> */
final readonly class RecordPaymentProcessor implements ProcessorInterface
{
    public function __construct(private ManagePayments $payments, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::PAYMENT_WRITE);

        try {
            $payment = $this->payments->record($company, CompanyPath::identifier($uriVariables, 'invoiceId'), $data->details(), $this->guard->account()->getId());
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidInvoice $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return PaymentResource::of($payment);
    }
}
