<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Module\Expenses\Domain\ExpenseCategoryRepository;
use App\Module\Expenses\Domain\TejOperationCode;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ExpenseOptionsResource> */
final readonly class ExpenseOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private CurrencyScales $scales,
        private ExpenseCategoryRepository $categories,
        private TaxComponentRepository $taxes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        $options = new ExpenseOptionsResource();
        $options->currency = $company->getCurrency();
        $options->currencyScale = $this->scales->of($company->getCurrency());
        foreach ($this->categories->ofCompany($company->getId()) as $category) {
            if ($category->isActive()) {
                $options->categories[] = ['id' => $category->getId()->toRfc4122(), 'name' => $category->getName(), 'parentId' => $category->getParent()?->getId()->toRfc4122()];
            }
        }
        foreach ($this->taxes->ofCompany($company->getId()) as $tax) {
            if ($tax->isActive() && TaxKind::PercentageLine === $tax->getKind()) {
                $options->taxes[] = ['id' => $tax->getId()->toRfc4122(), 'code' => $tax->getCode(), 'name' => $tax->getName(), 'rate' => (string) $tax->getRate()];
            }
        }
        $options->paymentMethods = ExpenseResource::methods();
        if (TejOperationCode::PRESET === $company->getFiscalPreset()) {
            $options->withholdingOperationCodes = array_map(static fn (TejOperationCode $code): array => ['code' => $code->value, 'label' => $code->label()], TejOperationCode::cases());
        }

        return $options;
    }
}
