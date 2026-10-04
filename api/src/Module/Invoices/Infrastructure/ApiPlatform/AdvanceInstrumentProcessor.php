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
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * One step of an instrument's life: deposit it, cash it (which records the payment) or mark it unpaid. The step is named
 * by the operation, so one class serves the three routes.
 *
 * @implements ProcessorInterface<mixed, PaymentInstrumentResource>
 */
final readonly class AdvanceInstrumentProcessor implements ProcessorInterface
{
    public function __construct(private ManageInstruments $instruments, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentInstrumentResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::PAYMENT_WRITE);
        $invoiceId = CompanyPath::identifier($uriVariables, 'invoiceId');
        $instrumentId = CompanyPath::identifier($uriVariables, 'instrumentId');
        $actor = $this->guard->account()->getId();

        try {
            $instrument = match ($operation->getExtraProperties()['step'] ?? null) {
                'deposit' => $this->instruments->deposit($company, $invoiceId, $instrumentId, $actor),
                'cash' => $this->instruments->cash($company, $invoiceId, $instrumentId, $actor),
                'unpaid' => $this->instruments->refuse($company, $invoiceId, $instrumentId, $actor),
                default => throw new \LogicException('An instrument route names its step.'),
            };
        } catch (InvoiceNotFound|InstrumentNotFound $absent) {
            throw new NotFoundHttpException('No such instrument.', $absent);
        } catch (InvoiceTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return PaymentInstrumentResource::of($instrument);
    }
}
