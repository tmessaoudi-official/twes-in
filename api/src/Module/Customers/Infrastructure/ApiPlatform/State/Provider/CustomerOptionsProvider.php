<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Fiscal\Application\Regime\ListCustomerTaxRegimes;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Customers\Infrastructure\ApiPlatform\Output\CustomerIdentifierOption;
use App\Module\Customers\Infrastructure\ApiPlatform\Output\CustomerRegimeOption;
use App\Module\Customers\Infrastructure\ApiPlatform\Output\CustomerTaxOption;
use App\Module\Customers\Infrastructure\ApiPlatform\Resource\CustomerOptionsResource;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @implements ProviderInterface<CustomerOptionsResource> */
final readonly class CustomerOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private ListCustomerTaxRegimes $regimes,
        private TaxComponentRepository $taxes,
        private TranslatorInterface $translator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);

        $options = new CustomerOptionsResource();
        $options->countryCode = $company->getCountryCode();
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $options->identifiers[] = new CustomerIdentifierOption(
                $identifier->key,
                $this->translator->trans($identifier->labelKey, [], 'fiscal'),
                $identifier->patternFor(IdentifierRules::BUSINESS_CUSTOMER),
                $identifier->isRequiredOf(IdentifierRules::BUSINESS_CUSTOMER),
            );
        }
        $options->regimes = array_map(fn (CustomerTaxRegime $regime) => new CustomerRegimeOption(
            $regime->getCode(),
            $this->translator->trans($regime->getLabelKey(), [], 'fiscal'),
            array_map(static fn (TaxFamily $family) => $family->value, $regime->getExcludedFamilies()),
        ), $this->regimes->for($company));
        $active = array_filter($this->taxes->ofCompany($company->getId()), static fn (TaxComponent $tax) => $tax->isActive());
        $options->taxes = array_values(array_map(static fn (TaxComponent $tax) => new CustomerTaxOption($tax->getId()->toRfc4122(), $tax->getCode(), $tax->getName(), $tax->getFamily()->value), $active));

        return $options;
    }
}
