<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Domain\UserRepository;
use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @implements ProviderInterface<VendorOptionsResource> */
final readonly class VendorOptionsProvider implements ProviderInterface
{
    /** The expenses module's key: vendors name no class of it, which depends on them and not the reverse. */
    private const string EXPENSES = 'expenses';

    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private UserRepository $users,
        private TranslatorInterface $translator,
        private ModuleStates $modules,
        private ExpenseCategoryDirectory $expenseCategories,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): VendorOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::READ);
        $locale = $this->users->ofId($this->guard->account()->getId())?->getLocale() ?? $company->getLocale();

        $options = new VendorOptionsResource();
        $options->countryCode = $company->getCountryCode();
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $options->identifiers[] = new VendorIdentifierOption(
                $identifier->key,
                $this->translator->trans($identifier->labelKey, [], 'fiscal', $locale),
                $identifier->patternFor(''),
            );
        }
        if ($this->modules->isEnabled($company->getId(), self::EXPENSES)) {
            $options->expenseCategories = [];
            foreach ($this->expenseCategories->categoriesInCompany($company->getId()) as $id => $category) {
                $options->expenseCategories[] = ['id' => $id, 'name' => $category['name'], 'active' => $category['active']];
            }
        }

        return $options;
    }
}
