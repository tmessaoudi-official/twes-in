<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\Regime\ListCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @implements ProviderInterface<CustomerTaxRegimeResource> */
final readonly class CustomerTaxRegimeCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ListCustomerTaxRegimes $regimes,
        private CompanyGuard $guard,
        private UserRepository $users,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<CustomerTaxRegimeResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), FiscalPermission::READ);
        $locale = $this->users->ofId($this->guard->account()->getId())?->getLocale() ?? $company->getLocale();

        return array_map(function (CustomerTaxRegime $regime) use ($locale): CustomerTaxRegimeResource {
            $resource = new CustomerTaxRegimeResource();
            $resource->code = $regime->getCode();
            $resource->label = $this->translator->trans($regime->getLabelKey(), [], 'fiscal', $locale);
            $resource->excludedFamilies = array_map(static fn (TaxFamily $family) => $family->value, $regime->getExcludedFamilies());
            $resource->hasMention = null !== $regime->getMentionKey();
            $resource->sortOrder = $regime->getSortOrder();

            return $resource;
        }, $this->regimes->for($company));
    }
}
