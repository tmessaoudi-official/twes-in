<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\Module\Vendors\Infrastructure\ApiPlatform\Output\VendorIdentifierOption;
use App\Module\Vendors\Infrastructure\ApiPlatform\Resource\VendorOptionsResource;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @implements ProviderInterface<VendorOptionsResource> */
final readonly class VendorOptionsProvider implements ProviderInterface
{
    /** The expenses module's key and its read: vendors name no class of it, which depends on them and not the reverse. */
    private const string EXPENSES = 'expenses';
    private const string EXPENSES_READ = 'expense.read';

    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private TranslatorInterface $translator,
        private ModuleStates $modules,
        private ExpenseCategoryDirectory $expenseCategories,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): VendorOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::READ);

        $options = new VendorOptionsResource();
        $options->countryCode = $company->getCountryCode();
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $options->identifiers[] = new VendorIdentifierOption(
                $identifier->key,
                $this->translator->trans($identifier->labelKey, [], 'fiscal'),
                $identifier->patternFor(''),
            );
        }
        // Named only to whoever may read them on their own page, while their module is on.
        if ($this->modules->isEnabled($company->getId(), self::EXPENSES) && $this->guard->may($company, self::EXPENSES_READ)) {
            $options->expenseCategories = [];
            foreach ($this->expenseCategories->categoriesInCompany($company->getId()) as $id => $category) {
                $options->expenseCategories[] = ['id' => $id, 'name' => $category['name'], 'active' => $category['active']];
            }
        }

        return $options;
    }
}
