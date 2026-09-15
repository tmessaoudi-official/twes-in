<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's stock locations (docs/SPEC.md § 4 stock_location): one tree per establishment, whose default location is
 * made the first time the establishment's stock is needed, so an establishment added at any time has one and nothing
 * outside this module has to know. A location given no parent sits under that default; its code is used once in the
 * establishment; it is deleted only once it holds no location and has seen no movement, and the default never is.
 * Audited with the names of the fields a revision changed, never their values; a default location is nobody's action.
 */
final readonly class ManageStockLocations
{
    public const string ENTITY_TYPE = 'stock_location';
    public const string CREATED = 'stock_location.created';
    public const string REVISED = 'stock_location.revised';
    public const string DELETED = 'stock_location.deleted';

    public function __construct(
        private StockLocationRepository $locations,
        private StockMovementRepository $movements,
        private EstablishmentRepository $establishments,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<StockLocation> every establishment's tree, its default location included */
    public function list(Company $company): array
    {
        foreach ($this->establishments->ofCompany($company->getId()) as $establishment) {
            $this->defaultOf($establishment);
        }

        return $this->locations->ofCompany($company->getId());
    }

    public function defaultOf(Establishment $establishment): StockLocation
    {
        $default = $this->locations->defaultOf($establishment->getId());
        if (null === $default) {
            $default = StockLocation::defaultOf($establishment, $this->clock->now());
            $this->locations->save($default);
        }

        return $default;
    }

    /** @throws StockLocationNotFound */
    public function get(Company $company, Uuid $id): StockLocation
    {
        return $this->locations->ofIdInCompany($id, $company->getId()) ?? throw new StockLocationNotFound();
    }

    public function childCount(StockLocation $location): int
    {
        return $this->locations->countChildren($location->getId());
    }

    public function movementCount(StockLocation $location): int
    {
        return $this->movements->countAt($location->getId());
    }

    /**
     * @throws StockLocationCodeTaken
     * @throws InvalidStockLocation
     */
    public function create(Company $company, Uuid $establishmentId, ?Uuid $parentId, StockLocationKind $kind, string $code, string $name, ?Uuid $actorUserId): StockLocation
    {
        $establishment = $this->establishments->ofIdInCompany($establishmentId, $company->getId())
            ?? throw new InvalidStockLocation('establishmentId', 'No establishment of this company has this id.');
        $parent = null === $parentId ? $this->defaultOf($establishment) : $this->parent($company, $parentId);
        if (null !== $this->locations->ofCodeInEstablishment(trim($code), $establishment->getId())) {
            throw new StockLocationCodeTaken();
        }

        $location = StockLocation::create($establishment, $parent, $kind, $code, $name, $this->clock->now());
        $this->locations->save($location);
        $this->record($company, $location->getId(), self::CREATED, [], $actorUserId);

        return $location;
    }

    /**
     * @return list<string> the fields that changed
     *
     * @throws StockLocationNotFound
     * @throws StockLocationCodeTaken
     * @throws InvalidStockLocation
     */
    public function revise(Company $company, Uuid $id, ?Uuid $parentId, StockLocationKind $kind, string $code, string $name, ?Uuid $actorUserId): array
    {
        $location = $this->get($company, $id);
        $parent = match (true) {
            null !== $parentId => $this->parent($company, $parentId),
            $location->isDefault() => null,
            default => $this->defaultOf($location->getEstablishment()),
        };
        $holder = $this->locations->ofCodeInEstablishment(trim($code), $location->getEstablishment()->getId());
        if (null !== $holder && !$holder->getId()->equals($location->getId())) {
            throw new StockLocationCodeTaken();
        }

        $changed = $location->revise($parent, $kind, $code, $name, $this->clock->now());
        if ([] !== $changed) {
            $this->locations->save($location);
            $this->record($company, $location->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
        }

        return $changed;
    }

    /**
     * @throws StockLocationNotFound
     * @throws StockLocationInUse
     */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $location = $this->get($company, $id);
        if ($location->isDefault()) {
            throw StockLocationInUse::asDefault();
        }
        $children = $this->childCount($location);
        $movements = $this->movementCount($location);
        if ($children > 0 || $movements > 0) {
            throw StockLocationInUse::holding($children, $movements);
        }
        $this->locations->remove($location);
        $this->record($company, $id, self::DELETED, [], $actorUserId);
    }

    private function parent(Company $company, Uuid $parentId): StockLocation
    {
        return $this->locations->ofIdInCompany($parentId, $company->getId())
            ?? throw new InvalidStockLocation('parentId', 'No stock location of this company has this id.');
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $locationId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $locationId, $action, $actorUserId, $changes, $company->getId()));
    }
}
