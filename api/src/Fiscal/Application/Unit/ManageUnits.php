<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Unit;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Domain\InvalidFiscalValue;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** A company's units: listed, added and revised, each change audited. */
final readonly class ManageUnits
{
    public const string ENTITY_TYPE = 'unit';
    public const string CREATED = 'unit.created';
    public const string REVISED = 'unit.revised';

    public function __construct(
        private UnitRepository $units,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Unit> */
    public function list(Company $company): array
    {
        return $this->units->ofCompany($company->getId());
    }

    /**
     * @throws UnitCodeTaken
     * @throws InvalidFiscalValue
     */
    public function create(Company $company, UnitDraft $draft, ?Uuid $actorUserId): Unit
    {
        if (null !== $this->units->ofCodeInCompany($draft->code, $company->getId())) {
            throw new UnitCodeTaken(\sprintf('The company already has a unit coded %s.', $draft->code));
        }
        $unit = Unit::create($company, $draft->code, $draft->name, $draft->decimals, $draft->sortOrder, $this->clock->now());
        $this->units->save($unit);
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $unit->getId(), self::CREATED, $actorUserId, self::snapshot($unit), $company->getId()));

        return $unit;
    }

    /**
     * @throws UnitNotFound       for a unit that does not exist or belongs to another company
     * @throws InvalidFiscalValue
     */
    public function revise(Company $company, Uuid $unitId, UnitChanges $changes, ?Uuid $actorUserId): Unit
    {
        $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new UnitNotFound('No such unit.');
        if ($unit->revise($changes->name, $changes->decimals, $changes->isActive, $changes->sortOrder, $this->clock->now())) {
            $this->units->save($unit);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $unit->getId(), self::REVISED, $actorUserId, self::snapshot($unit), $company->getId()));
        }

        return $unit;
    }

    /** @return array<string, mixed> */
    private static function snapshot(Unit $unit): array
    {
        return ['code' => $unit->getCode(), 'name' => $unit->getName(), 'decimals' => $unit->getDecimals(), 'is_active' => $unit->isActive()];
    }
}
