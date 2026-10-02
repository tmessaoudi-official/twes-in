<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Invoices\Application\ManageCustomerCredit;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<CustomerCreditBalanceResource> */
final readonly class CustomerCreditBalanceProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageCustomerCredit $credit, private CurrencyScales $scales)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerCreditBalanceResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // The balance is a customer's money: it takes the right to see both.
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $company = $this->guard->companyForActing($companyId, InvoicePermission::READ);
        $customerId = CompanyPath::identifier($uriVariables, 'customerId');

        try {
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
        }
    }
}
