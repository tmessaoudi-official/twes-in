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
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @implements ProviderInterface<VendorOptionsResource> */
final readonly class VendorOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private UserRepository $users,
        private TranslatorInterface $translator,
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
                $identifier->pattern,
            );
        }

        return $options;
    }
}
