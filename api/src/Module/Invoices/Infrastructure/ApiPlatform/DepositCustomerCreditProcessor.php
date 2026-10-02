<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Invoices\Application\ManageCustomerCredit;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<CustomerCreditBalanceResource, CustomerCreditBalanceResource> */
final readonly class DepositCustomerCreditProcessor implements ProcessorInterface
{
    public function __construct(private CompanyGuard $guard, private ManageCustomerCredit $credit, private CurrencyScales $scales)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerCreditBalanceResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $company = $this->guard->companyForActing($companyId, InvoicePermission::PAYMENT_WRITE);
        $customerId = CompanyPath::identifier($uriVariables, 'customerId');

        try {
            $this->credit->deposit($company, $customerId, $data->details(), $this->guard->account()->getId());
            $scale = $this->scales->of($company->getCurrency());

            return CustomerCreditBalanceResource::of(
                $customerId->toRfc4122(),
                Decimal::format(Decimal::of($this->credit->balance($company, $customerId)), $scale),
                $company->getCurrency(),
                $scale,
                $this->credit->entries($company, $customerId),
            );
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }
    }
}
