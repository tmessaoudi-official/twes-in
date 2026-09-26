<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** Every declared module. A declaration that could never be honoured stops the application from booting. */
final readonly class ModuleCatalog
{
    /** @var array<string, ModuleManifest> by key, in key order */
    private array $manifests;

    /**
     * @param iterable<DeclaresModule> $declarations
     *
     * @throws \LogicException when a key is declared twice, or a dependency is undeclared or circular (the module itself
     *                         included), or a module that ships depends on one only planned
     */
    public function __construct(iterable $declarations, ?PlannedModules $planned = null)
    {
        $manifests = [];
        $declared = [];
        foreach ($declarations as $declaration) {
            $declared[] = $declaration->manifest();
        }
        // A module that ships replaces its planned entry: declaring both fails here, never merges silently.
        foreach ([...$declared, ...($planned?->manifests() ?? [])] as $manifest) {
            if (isset($manifests[$manifest->key])) {
                throw new \LogicException(\sprintf('The module %s is declared twice.', $manifest->key));
            }
            $manifests[$manifest->key] = $manifest;
        }
        ksort($manifests);
        foreach ($manifests as $manifest) {
            foreach ($manifest->dependencies as $dependency) {
                if (!isset($manifests[$dependency])) {
                    throw new \LogicException(\sprintf('The module %s depends on %s, which is not declared.', $manifest->key, $dependency));
                }
                if (null === $manifest->planned && null !== $manifests[$dependency]->planned) {
                    throw new \LogicException(\sprintf('The module %s depends on %s, which is only planned: it could never be switched on.', $manifest->key, $dependency));
                }
            }
        }
        $finished = [];
        foreach (array_keys($manifests) as $key) {
            self::refuseCycle($manifests, $key, [], $finished);
        }
        $this->manifests = $manifests;
    }

    /** @return list<ModuleManifest> in key order */
    public function all(): array
    {
        return array_values($this->manifests);
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->manifests[$key] ?? null;
    }

    /** @return list<string> the keys of the modules that cannot be on without this one, in key order */
    public function dependentsOf(string $key): array
    {
        $dependents = [];
        foreach ($this->manifests as $manifest) {
            if (\in_array($key, $manifest->dependencies, true)) {
                $dependents[] = $manifest->key;
            }
        }

        return $dependents;
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     * @param array<string, true>           $path      the modules walked to reach this one
     * @param array<string, true>           $finished  the modules already known to lead to no cycle
     *
     * @throws \LogicException
     */
    private static function refuseCycle(array $manifests, string $key, array $path, array &$finished): void
    {
        if (isset($finished[$key])) {
            return;
        }
        if (isset($path[$key])) {
            throw new \LogicException(\sprintf('The dependencies of %s form a cycle: %s.', $key, implode(' → ', [...array_keys($path), $key])));
        }
        $path[$key] = true;
        foreach ($manifests[$key]->dependencies as $dependency) {
            self::refuseCycle($manifests, $dependency, $path, $finished);
        }
        $finished[$key] = true;
    }
}
