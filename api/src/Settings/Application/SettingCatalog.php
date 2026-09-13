<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;

/** Every declared setting. A key it does not know can be neither read nor written. */
final readonly class SettingCatalog
{
    /** @var list<SettingDefinition> */
    private array $definitions;

    /**
     * @param iterable<DeclaresSettings> $declarations
     *
     * @throws \LogicException when two modules declare the same key
     */
    public function __construct(iterable $declarations)
    {
        $definitions = [];
        $keys = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration->settings() as $definition) {
                if (isset($keys[$definition->key])) {
                    throw new \LogicException(\sprintf('The setting %s is declared twice.', $definition->key));
                }
                $keys[$definition->key] = true;
                $definitions[] = $definition;
            }
        }
        $this->definitions = $definitions;
    }

    /** An exact key wins over a pattern that would also cover it. */
    public function definitionOf(string $key): ?SettingDefinition
    {
        foreach ($this->definitions as $definition) {
            if (!$definition->isPattern() && $definition->key === $key) {
                return $definition;
            }
        }
        foreach ($this->definitions as $definition) {
            if ($definition->isPattern() && $definition->matches($key)) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<SettingDefinition> in the order they were declared */
    public function ofChain(SettingChain $chain): array
    {
        return array_values(array_filter($this->definitions, static fn (SettingDefinition $definition) => $definition->chain === $chain));
    }
}
