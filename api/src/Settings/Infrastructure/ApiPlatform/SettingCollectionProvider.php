<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Settings\Application\ResolveSettings;
use App\Settings\Domain\SettingChain;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProviderInterface<SettingResource> */
final readonly class SettingCollectionProvider implements ProviderInterface
{
    public function __construct(private ResolveSettings $resolve, private SettingAccess $access)
    {
    }

    /** @return list<SettingResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->access->companyToRead(CompanyPath::identifier($uriVariables, 'companyId'));
        $settingContext = $this->access->contextOf($company);
        $mayShare = $this->access->mayShare($company);

        $rows = [];
        foreach ($this->chains($context) as $chain) {
            foreach ($this->resolve->handle($chain, $settingContext) as $setting) {
                $rows[] = SettingResource::of($setting, $this->access->writableLevels($setting->definition, $mayShare));
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<SettingChain>
     */
    private function chains(array $context): array
    {
        $filters = $context['filters'] ?? [];
        $chain = \is_array($filters) ? ($filters['chain'] ?? null) : null;
        if (null === $chain) {
            return SettingChain::cases();
        }
        if (!\is_string($chain) || null === SettingChain::tryFrom($chain)) {
            throw new BadRequestHttpException('chain: expected presentation, parties or articles.');
        }

        return [SettingChain::from($chain)];
    }
}
