<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\ModuleRegistry\Domain\ModuleInterest;
use App\ModuleRegistry\Domain\ModuleInterestRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Me prévenir » (docs/SPEC.md § 7, 2026-09-26 10:08, row 150). A company asks to be told when a planned module
 * arrives, or stops asking; the operator reads how many companies wait for each; the day a module ships (its key
 * leaves PlannedModules for its own declaration), the members who may switch it on are told, once. Asking and
 * withdrawing are audited as the module's, so the modules page of every other tab reloads.
 */
final readonly class ModuleInterests
{
    public const string RECORDED = 'module.interest_recorded';
    public const string WITHDRAWN = 'module.interest_withdrawn';
    /** The notification: a module the company asked for is here. */
    public const string ARRIVED = 'module.arrived';
    /** Who is told: those who may switch the module on. */
    public const string PERMISSION = 'company.settings';

    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleInterestRepository $rows,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<string> the planned modules the company waits for, in key order */
    public function keysOf(Uuid $companyId): array
    {
        $keys = array_map(static fn (ModuleInterest $row) => $row->getKey(), $this->rows->waitingOfCompany($companyId));
        sort($keys);

        return $keys;
    }

    /**
     * @return bool whether the company now waits for the module; asking twice, or withdrawing what was never asked,
     *              records nothing
     *
     * @throws UnknownModule
     * @throws ModuleAlreadyAvailable
     */
    public function set(Company $company, string $key, bool $interested, ?Uuid $actorUserId): bool
    {
        return $this->transactions->run(function () use ($company, $key, $interested, $actorUserId): bool {
            $manifest = $this->catalog->get($key) ?? throw new UnknownModule($key);
            if (null === $manifest->planned) {
                throw new ModuleAlreadyAvailable($key);
            }
            $companyId = $company->getId();
            $row = $this->rows->ofKeyInCompany($key, $companyId);
            if ((null !== $row) === $interested) {
                return $interested;
            }

            if ($interested) {
                $row = new ModuleInterest($company, $key, $this->clock->now());
                $this->rows->save($row);
            } else {
                \assert(null !== $row);
                $this->rows->remove($row);
            }
            $this->audit->record(new AuditEntry(ManageModules::ENTITY_TYPE, $row->getId(), $interested ? self::RECORDED : self::WITHDRAWN, $actorUserId, ['key' => $key], $companyId));

            return $interested;
        });
    }

    /** @return list<ModuleDemand> every planned module, the most asked for first, then by key */
    public function demand(): array
    {
        $counts = [];
        foreach ($this->rows->waiting() as $row) {
            $counts[$row->getKey()] = ($counts[$row->getKey()] ?? 0) + 1;
        }
        $demand = [];
        foreach ($this->catalog->all() as $manifest) {
            if (null !== $manifest->planned) {
                $demand[] = new ModuleDemand($manifest, $counts[$manifest->key] ?? 0);
            }
        }
        usort($demand, static fn (ModuleDemand $a, ModuleDemand $b) => [$b->companies, $a->manifest->key] <=> [$a->companies, $b->manifest->key]);

        return $demand;
    }

    /**
     * Tells each company waiting for a module that now ships, through the members who may switch it on, and marks the
     * wait told so a restart tells nobody again. Run at every start of the api, after the migrations.
     *
     * @return int how many companies' waits were told
     */
    public function announceArrivals(): int
    {
        return $this->transactions->run(function (): int {
            $now = $this->clock->now();
            $told = 0;
            foreach ($this->rows->waiting() as $row) {
                $manifest = $this->catalog->get($row->getKey());
                // Still planned, or dropped from the plan altogether: nothing has arrived.
                if (null === $manifest || null !== $manifest->planned) {
                    continue;
                }
                $company = $row->getCompany();
                foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
                    if ($membership->getRole()->grants(self::PERMISSION)) {
                        $this->notifications->publish(new Notification(
                            'user:'.$membership->getUser()->getId()->toRfc4122(),
                            self::ARRIVED,
                            ['module' => $manifest->key, 'label_key' => $manifest->labelKey, 'company' => $company->getName()],
                        ));
                    }
                }
                $row->announce($now);
                $this->rows->save($row);
                ++$told;
            }

            return $told;
        });
    }
}
