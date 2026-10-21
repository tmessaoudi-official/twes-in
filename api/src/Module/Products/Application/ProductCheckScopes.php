<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Products\Domain\ValueObject\ProductCheckScope;
use App\Module\Products\Domain\ValueObject\ProductSearch;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Domain\Exception\InvalidFilter;
use App\Tenancy\Domain\Model\Company;

/**
 * What of a product's « À vérifier » may be said in a company, to a reader: the count while the company keeps stock,
 * which is its inventory module switched on; the VAT unless its own regime charges none; the cost to a cost reader.
 */
final readonly class ProductCheckScopes
{
    /** The module that keeps stock, named by its key: products never name another module's classes. */
    public const string STOCK_MODULE = 'inventory';

    public function __construct(private ModuleStates $modules, private ExcludedTaxFamilies $regimes)
    {
    }

    public function of(Company $company, bool $seesCosts): ProductCheckScope
    {
        return new ProductCheckScope(
            keepsStock: $this->modules->isEnabled($company->getId(), self::STOCK_MODULE),
            chargesVat: !\in_array(TaxFamily::Vat, $this->regimes->companyRegime($company)->excludedFamilies ?? [], true),
            seesCosts: $seesCosts,
        );
    }

    /**
     * The search told what its reader may be told; a reason asked for that may not be said is refused, never answered
     * empty, as an empty answer would still say whether something lacks it.
     *
     * @throws InvalidFilter
     */
    public function scoped(Company $company, ProductSearch $search, bool $seesCosts): ProductSearch
    {
        $scope = $this->of($company, $seesCosts);
        foreach ($search->checkReasons as $reason) {
            $scope->says($reason) || throw new InvalidFilter('checkReason', \sprintf('"%s" is not said here.', $reason->value));
        }

        return $search->withCheckScope($scope);
    }
}
