<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

use App\ModuleRegistry\Domain\ModuleStateRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Which modules a company has on. A module the company never switched is on: plans will gate modules once the
 * licensing module exists (docs/SPEC.md § 3 Modules), and until then every company has all of them. A key no module
 * declares is never on.
 */
final readonly class ModuleStates
{
    public function __construct(private ModuleCatalog $catalog, private ModuleStateRepository $states)
    {
    }

    public function isEnabled(Uuid $companyId, string $key): bool
    {
        if (null === $this->catalog->get($key)) {
            return false;
        }

        return $this->states->ofKeyInCompany($key, $companyId)?->isEnabled() ?? true;
    }

    /** @return list<string> in key order */
    public function enabledKeys(Uuid $companyId): array
    {
        $switched = [];
        foreach ($this->states->ofCompany($companyId) as $state) {
            $switched[$state->getKey()] = $state;
        }

        return array_values(array_map(
            static fn (ModuleManifest $manifest) => $manifest->key,
            array_filter($this->catalog->all(), static fn (ModuleManifest $manifest) => ($switched[$manifest->key] ?? null)?->isEnabled() ?? true),
        ));
    }
}
