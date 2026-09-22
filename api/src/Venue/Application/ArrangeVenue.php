<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\StructureKind;
use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueAreaRepository;
use App\Venue\Domain\VenueSpot;
use App\Venue\Domain\VenueSpotRepository;
use App\Venue\Domain\VenueStructure;
use App\Venue\Domain\VenueStructureRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Drawing a company's places (docs/SPEC.md § 4 venue_area, venue_spot; § 7, 2026-09-14 and 2026-09-19 23:40). An
 * establishment draws one area per floor and rectangles on it; nothing here knows what a rectangle holds, because a
 * consumer binds its own record to a spot — the inventory a stock location, a dining room later a table.
 *
 * Every change is audited, which is also the signal open plans reload on: a use case that recorded nothing would
 * leave every other screen showing yesterday's drawing with no error anywhere.
 */
final readonly class ArrangeVenue
{
    public const string AREA_TYPE = 'venue_area';
    public const string SPOT_TYPE = 'venue_spot';
    public const string AREA_CREATED = 'venue_area.created';
    public const string AREA_REVISED = 'venue_area.revised';
    public const string AREA_DELETED = 'venue_area.deleted';
    public const string SPOT_CREATED = 'venue_spot.created';
    public const string SPOT_REVISED = 'venue_spot.revised';
    public const string SPOT_DELETED = 'venue_spot.deleted';
    public const string STRUCTURE_TYPE = 'venue_structure';
    public const string STRUCTURE_CREATED = 'venue_structure.created';
    public const string STRUCTURE_REVISED = 'venue_structure.revised';
    public const string STRUCTURE_DELETED = 'venue_structure.deleted';

    public function __construct(
        private VenueAreaRepository $areas,
        private VenueSpotRepository $spots,
        private VenueStructureRepository $structures,
        private EstablishmentRepository $establishments,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<VenueArea> every floor the company draws, by establishment then from the ground up */
    public function areas(Company $company): array
    {
        return $this->areas->ofCompany($company->getId());
    }

    /** @throws VenueAreaNotFound */
    public function area(Company $company, Uuid $areaId): VenueArea
    {
        return $this->areas->ofIdInCompany($areaId, $company->getId()) ?? throw new VenueAreaNotFound();
    }

    /**
     * @return list<VenueSpot>
     *
     * @throws VenueAreaNotFound
     */
    public function spotsOf(Company $company, Uuid $areaId): array
    {
        return $this->spots->ofArea($this->area($company, $areaId)->getId());
    }

    /**
     * @throws VenueAreaNotFound
     * @throws VenueLevelTaken
     * @throws InvalidVenue
     */
    public function addArea(Company $company, Uuid $establishmentId, string $name, int $level, ?Uuid $actorUserId): VenueArea
    {
        return $this->transactions->run(function () use ($company, $establishmentId, $name, $level, $actorUserId): VenueArea {
            $establishment = $this->establishments->ofIdInCompany($establishmentId, $company->getId())
                ?? throw new VenueAreaNotFound();
            if (null !== $this->areas->ofLevelInEstablishment($level, $establishment->getId())) {
                throw new VenueLevelTaken();
            }
            $area = VenueArea::create($establishment, $name, $level, $this->clock->now());
            $this->areas->save($area);
            $this->record(self::AREA_TYPE, $area->getId(), self::AREA_CREATED, $actorUserId, $company);

            return $area;
        });
    }

    /**
     * @throws VenueAreaNotFound
     * @throws VenueLevelTaken
     * @throws InvalidVenue
     */
    public function reviseArea(Company $company, Uuid $areaId, string $name, int $level, ?Uuid $actorUserId): VenueArea
    {
        return $this->transactions->run(function () use ($company, $areaId, $name, $level, $actorUserId): VenueArea {
            $area = $this->area($company, $areaId);
            $taken = $this->areas->ofLevelInEstablishment($level, $area->getEstablishment()->getId());
            if (null !== $taken && !$taken->getId()->equals($area->getId())) {
                throw new VenueLevelTaken();
            }
            if ($area->rename($name, $level, $this->clock->now())) {
                $this->areas->save($area);
                $this->record(self::AREA_TYPE, $area->getId(), self::AREA_REVISED, $actorUserId, $company);
            }

            return $area;
        });
    }

    /**
     * The plan behind the drawing, its scale and how much of it shows; a null file takes it away.
     *
     * @throws VenueAreaNotFound
     * @throws InvalidVenue
     */
    public function showPlan(Company $company, Uuid $areaId, ?Uuid $imageFileId, ?string $metresWide, int $opacity, ?Uuid $actorUserId): VenueArea
    {
        return $this->transactions->run(function () use ($company, $areaId, $imageFileId, $metresWide, $opacity, $actorUserId): VenueArea {
            $area = $this->area($company, $areaId);
            if ($area->showPlan($imageFileId, $metresWide, $opacity, $this->clock->now())) {
                $this->areas->save($area);
                $this->record(self::AREA_TYPE, $area->getId(), self::AREA_REVISED, $actorUserId, $company);
            }

            return $area;
        });
    }

    /**
     * A floor stops being drawn, and what was drawn on it goes with it. A consumer bound to one of those rectangles
     * is left undrawn, never deleted: a stock location that is not on a plan is still a stock location.
     *
     * @throws VenueAreaNotFound
     */
    public function removeArea(Company $company, Uuid $areaId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $areaId, $actorUserId): void {
            $area = $this->area($company, $areaId);
            foreach ($this->spots->ofArea($area->getId()) as $spot) {
                $this->spots->remove($spot);
            }
            // The building the floor carried goes with it. It is drawn on that floor and on no other, so leaving it
            // behind would orphan every wall of a plan nobody can open again.
            foreach ($this->structures->ofArea($area->getId()) as $structure) {
                $this->structures->remove($structure);
            }
            $this->areas->remove($area);
            $this->record(self::AREA_TYPE, $area->getId(), self::AREA_DELETED, $actorUserId, $company);
        });
    }

    /**
     * @throws VenueAreaNotFound
     * @throws InvalidVenue
     */
    public function place(Company $company, Uuid $areaId, PlanRect $rect, ?Uuid $actorUserId): VenueSpot
    {
        return $this->transactions->run(function () use ($company, $areaId, $rect, $actorUserId): VenueSpot {
            $spot = VenueSpot::place($this->area($company, $areaId), $rect, $this->clock->now());
            $this->spots->save($spot);
            $this->record(self::SPOT_TYPE, $spot->getId(), self::SPOT_CREATED, $actorUserId, $company);

            return $spot;
        });
    }

    /**
     * @throws VenueSpotNotFound
     * @throws InvalidVenue
     */
    public function moveSpot(Company $company, Uuid $spotId, PlanRect $rect, ?Uuid $actorUserId): VenueSpot
    {
        return $this->transactions->run(function () use ($company, $spotId, $rect, $actorUserId): VenueSpot {
            $spot = $this->spot($company, $spotId);
            // Dropped where it already was: no row, so no other screen is told the plan moved.
            if ($spot->moveTo($rect, $this->clock->now())) {
                $this->spots->save($spot);
                $this->record(self::SPOT_TYPE, $spot->getId(), self::SPOT_REVISED, $actorUserId, $company);
            }

            return $spot;
        });
    }

    /** @throws VenueSpotNotFound */
    public function removeSpot(Company $company, Uuid $spotId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $spotId, $actorUserId): void {
            $spot = $this->spot($company, $spotId);
            $this->spots->remove($spot);
            $this->record(self::SPOT_TYPE, $spot->getId(), self::SPOT_DELETED, $actorUserId, $company);
        });
    }

    /** @throws VenueSpotNotFound */
    public function spot(Company $company, Uuid $spotId): VenueSpot
    {
        return $this->spots->ofIdInCompany($spotId, $company->getId()) ?? throw new VenueSpotNotFound();
    }

    /**
     * The building drawn on one floor — walls, doors, posts, docks. It is listed apart from the spots because it
     * IS apart: nothing binds itself to a wall, and the screen draws it under the stock, as its own layer.
     *
     * @return list<VenueStructure>
     *
     * @throws VenueAreaNotFound
     */
    public function structuresOf(Company $company, Uuid $areaId): array
    {
        return $this->structures->ofArea($this->area($company, $areaId)->getId());
    }

    /**
     * @throws VenueAreaNotFound
     * @throws InvalidVenue
     */
    public function build(Company $company, Uuid $areaId, StructureKind $kind, string $name, PlanRect $rect, ?Uuid $actorUserId): VenueStructure
    {
        return $this->transactions->run(function () use ($company, $areaId, $kind, $name, $rect, $actorUserId): VenueStructure {
            $structure = VenueStructure::build($this->area($company, $areaId), $kind, $name, $rect, $this->clock->now());
            $this->structures->save($structure);
            $this->record(self::STRUCTURE_TYPE, $structure->getId(), self::STRUCTURE_CREATED, $actorUserId, $company);

            return $structure;
        });
    }

    /**
     * @throws VenueStructureNotFound
     * @throws InvalidVenue
     */
    public function reshapeStructure(Company $company, Uuid $structureId, StructureKind $kind, string $name, PlanRect $rect, ?Uuid $actorUserId): VenueStructure
    {
        return $this->transactions->run(function () use ($company, $structureId, $kind, $name, $rect, $actorUserId): VenueStructure {
            $structure = $this->structure($company, $structureId);
            // Left exactly as it stood: no row, so no other screen is told the building moved.
            if ($structure->reshape($kind, $name, $rect, $this->clock->now())) {
                $this->structures->save($structure);
                $this->record(self::STRUCTURE_TYPE, $structure->getId(), self::STRUCTURE_REVISED, $actorUserId, $company);
            }

            return $structure;
        });
    }

    /** @throws VenueStructureNotFound */
    public function removeStructure(Company $company, Uuid $structureId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $structureId, $actorUserId): void {
            $structure = $this->structure($company, $structureId);
            $this->structures->remove($structure);
            $this->record(self::STRUCTURE_TYPE, $structure->getId(), self::STRUCTURE_DELETED, $actorUserId, $company);
        });
    }

    /** @throws VenueStructureNotFound */
    public function structure(Company $company, Uuid $structureId): VenueStructure
    {
        return $this->structures->ofIdInCompany($structureId, $company->getId()) ?? throw new VenueStructureNotFound();
    }

    private function record(string $type, Uuid $id, string $action, ?Uuid $actorUserId, Company $company): void
    {
        $this->audit->record(new AuditEntry($type, $id, $action, $actorUserId, companyId: $company->getId()));
    }
}
