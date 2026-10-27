<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingResource;

/**
 * The platform chain as its operators see it, and every company setting whose installation default is theirs; the
 * operation's security has already required an operator.
 *
 * @implements ProviderInterface<SettingResource>
 */
final readonly class PlatformSettingCollectionProvider implements ProviderInterface
{
    public function __construct(private ResolveSettings $resolve)
    {
    }

    /** @return list<SettingResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $rows = [];
        foreach ($this->resolve->handle(SettingChain::Platform, new SettingContext()) as $setting) {
            $rows[] = SettingResource::of($setting, $setting->definition->allows(SettingLevel::Platform) ? [SettingLevel::Platform->value] : []);
        }
        // A company's setting whose installation default is the operator's, read with no company: the platform's value,
        // else the default.
        foreach (SettingChain::ofCompanies() as $chain) {
            foreach ($this->resolve->handle($chain, new SettingContext()) as $setting) {
                if ($setting->definition->allows(SettingLevel::Platform)) {
                    $rows[] = SettingResource::of($setting, [SettingLevel::Platform->value]);
                }
            }
        }

        return $rows;
    }
}
