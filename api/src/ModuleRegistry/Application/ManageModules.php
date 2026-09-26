<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\ModuleRegistry\Domain\ModuleState;
use App\ModuleRegistry\Domain\ModuleStateRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company switches its modules on and off (docs/SPEC.md § 3 Modules). A module goes on only once every module it
 * needs is on, and stays on while an enabled module needs it, so what is on always has what it needs. Switching a
 * module off keeps its data. Audited with the module's key; switching to the current state records nothing.
 */
final readonly class ManageModules
{
    public const string ENTITY_TYPE = 'module';
    public const string ENABLED = 'module.enabled';
    public const string DISABLED = 'module.disabled';

    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleStateRepository $rows,
        private ModuleStates $states,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<ModuleView> every declared module, in key order */
    public function list(Company $company): array
    {
        $enabled = $this->states->enabledKeys($company->getId());

        return array_map(static fn (ModuleManifest $manifest) => new ModuleView($manifest, \in_array($manifest->key, $enabled, true)), $this->catalog->all());
    }

    /**
     * @throws UnknownModule
     * @throws ModuleNotAvailable
     * @throws ModuleDependenciesDisabled
     * @throws ModuleRequired
     */
    public function switch(Company $company, string $key, bool $enabled, ?Uuid $actorUserId): ModuleView
    {
        return $this->transactions->run(function () use ($company, $key, $enabled, $actorUserId): ModuleView {
            $manifest = $this->catalog->get($key) ?? throw new UnknownModule($key);
            if (null !== $manifest->planned) {
                throw new ModuleNotAvailable($key);
            }
            $companyId = $company->getId();
            if ($this->states->isEnabled($companyId, $key) === $enabled) {
                return new ModuleView($manifest, $enabled);
            }

            if ($enabled) {
                $off = array_values(array_filter($manifest->dependencies, fn (string $dependency) => !$this->states->isEnabled($companyId, $dependency)));
                if ([] !== $off) {
                    sort($off);
                    throw new ModuleDependenciesDisabled($key, $off);
                }
            } else {
                $needing = array_values(array_filter($this->catalog->dependentsOf($key), fn (string $dependent) => $this->states->isEnabled($companyId, $dependent)));
                if ([] !== $needing) {
                    throw new ModuleRequired($key, $needing);
                }
            }

            $now = $this->clock->now();
            $state = $this->rows->ofKeyInCompany($key, $companyId);
            if (null === $state) {
                $state = ModuleState::of($company, $key, $enabled, $now);
            } else {
                $state->switchTo($enabled, $now);
            }
            $this->rows->save($state);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $state->getId(), $enabled ? self::ENABLED : self::DISABLED, $actorUserId, ['key' => $key], $companyId));

            return new ModuleView($manifest, $enabled);
        });
    }
}
