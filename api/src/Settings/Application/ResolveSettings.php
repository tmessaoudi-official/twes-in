<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingRepository;

/**
 * Walks a chain for one context: the most specific level holding a value wins, down to the declared default. A
 * stored value is skipped when its level no longer allows the setting or the setting no longer accepts it, so a
 * row written by an earlier release never breaks a read.
 */
final readonly class ResolveSettings
{
    public function __construct(private SettingCatalog $catalog, private SettingRepository $settings)
    {
    }

    /** @return list<ResolvedSetting> every declared setting of the chain, then each key a pattern covers that holds a value */
    public function handle(SettingChain $chain, SettingContext $context): array
    {
        $stored = $this->stored($context->addresses($chain));
        $resolved = [];
        foreach ($this->catalog->ofChain($chain) as $definition) {
            if (!$definition->isPattern()) {
                $resolved[] = $this->resolve($definition->key, $definition, $stored[$definition->key] ?? []);
                continue;
            }
            $keys = [];
            foreach (array_keys($stored) as $key) {
                if ($this->catalog->definitionOf((string) $key) === $definition) {
                    $keys[] = (string) $key;
                }
            }
            sort($keys);
            foreach ($keys as $key) {
                $one = $this->resolve($key, $definition, $stored[$key]);
                if ([] !== $one->explicit) {
                    $resolved[] = $one;
                }
            }
        }

        return $resolved;
    }

    /** @throws UnknownSetting */
    public function one(SettingContext $context, string $key): ResolvedSetting
    {
        $definition = $this->catalog->definitionOf($key) ?? throw new UnknownSetting(\sprintf('No setting is declared as %s.', $key));

        return $this->resolve($key, $definition, $this->stored($context->addresses($definition->chain))[$key] ?? []);
    }

    /**
     * @param list<SettingAddress> $addresses
     *
     * @return array<string, array<string, mixed>> by key, then by level
     */
    private function stored(array $addresses): array
    {
        $stored = [];
        foreach ($this->settings->at($addresses) as $setting) {
            $stored[$setting->getKey()][$setting->getLevel()->value] = $setting->getValue();
        }

        return $stored;
    }

    /** @param array<string, mixed> $stored by level */
    private function resolve(string $key, SettingDefinition $definition, array $stored): ResolvedSetting
    {
        $explicit = [];
        $value = $definition->default;
        $source = null;
        foreach ($definition->chain->levels() as $level) {
            if (!\array_key_exists($level->value, $stored) || !$definition->allows($level) || null !== $definition->refusal($stored[$level->value])) {
                continue;
            }
            $explicit[$level->value] = $stored[$level->value];
            $value = $stored[$level->value];
            $source = $level;
        }

        return new ResolvedSetting($key, $definition, $value, $source, $explicit);
    }
}
