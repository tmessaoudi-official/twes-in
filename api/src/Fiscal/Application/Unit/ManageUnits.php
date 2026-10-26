<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Unit;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\DocumentLanguageNames;
use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** A company's units: listed, added and revised, each change audited. */
final readonly class ManageUnits implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'unit';
    public const string CREATED = 'unit.created';
    public const string REVISED = 'unit.revised';

    public function __construct(
        private UnitRepository $units,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
        private DocumentLanguageNames $languageNames,
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
        return $this->transactions->run(function () use ($company, $draft, $actorUserId): Unit {
            if (null !== $this->units->ofCodeInCompany($draft->code, $company->getId())) {
                throw new UnitCodeTaken(\sprintf('The company already has a unit coded %s.', $draft->code));
            }
            $unit = Unit::create($company, $draft->code, $draft->name, $draft->decimals, $draft->sortOrder, $this->clock->now(), $this->languageNames->checked($draft->names) ?? []);
            $this->units->save($unit);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $unit->getId(), self::CREATED, $actorUserId, self::snapshot($unit), $company->getId()));

            return $unit;
        });
    }

    /**
     * `$version` is the one the editor opened; a caller revising what it finds names none.
     *
     * @throws UnitNotFound       for a unit that does not exist or belongs to another company
     * @throws InvalidFiscalValue
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $unitId, UnitChanges $changes, ?Uuid $actorUserId, ?int $version = null): Unit
    {
        return $this->transactions->run(function () use ($company, $unitId, $changes, $actorUserId, $version): Unit {
            $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new UnitNotFound('No such unit.');
            if (null !== $version) {
                $this->versions->expect($unit, $version);
            }
            if ($unit->revise($changes->name, $changes->decimals, $changes->isActive, $changes->sortOrder, $this->clock->now(), $this->languageNames->checked($changes->names))) {
                $this->units->save($unit);
                $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $unit->getId(), self::REVISED, $actorUserId, self::snapshot($unit), $company->getId()));
            }

            return $unit;
        });
    }

    /** @return array<string, mixed> */
    private static function snapshot(Unit $unit): array
    {
        return ['code' => $unit->getCode(), 'name' => $unit->getName(), 'names' => $unit->getNames(), 'decimals' => $unit->getDecimals(), 'is_active' => $unit->isActive()];
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
