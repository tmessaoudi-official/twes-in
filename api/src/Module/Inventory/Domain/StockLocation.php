<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Venue\Domain\VenueSpot;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Where goods are kept (docs/SPEC.md § 4 stock_location): a tree per establishment. Its top is the establishment's
 * default location, a site coded and named after it; every other location sits under a location of the same
 * establishment, never under itself or one of its own. A code is used once in an establishment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_location')]
#[ORM\Index(name: 'idx_stock_location_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_stock_location_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_stock_location_spot', columns: ['spot_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_location_establishment_code', columns: ['establishment_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_location_default', columns: ['establishment_id'], options: ['where' => 'is_default'])]
class StockLocation implements CompanyOwned
{
    public const int CODE_MAX = 32;
    public const int NAME_MAX = 120;
    private const string CODE = '/^[A-Za-z0-9._-]{1,32}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Establishment $establishment;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?StockLocation $parent = null;

    /** Where it sits on a plan, if anyone has drawn it: the venue owns the rectangle, this owns the binding. */
    #[ORM\ManyToOne(targetEntity: VenueSpot::class)]
    #[ORM\JoinColumn(name: 'spot_id', nullable: true, onDelete: 'SET NULL')]
    private ?VenueSpot $spot = null;

    #[ORM\Column(length: 16, enumType: StockLocationKind::class)]
    private StockLocationKind $kind;

    #[ORM\Column(length: self::CODE_MAX)]
    private string $code;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Establishment $establishment, StockLocationKind $kind, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $establishment->getCompany();
        $this->establishment = $establishment;
        $this->kind = $kind;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** The top of an establishment's tree: a site with the establishment's code and name. */
    public static function defaultOf(Establishment $establishment, \DateTimeImmutable $now): self
    {
        $location = new self($establishment, StockLocationKind::Site, $now);
        $location->code = self::code($establishment->getCode());
        $location->name = self::name(mb_substr($establishment->getName(), 0, self::NAME_MAX));
        $location->isDefault = true;

        return $location;
    }

    /** @throws InvalidStockLocation */
    public static function create(Establishment $establishment, ?self $parent, StockLocationKind $kind, string $code, string $name, \DateTimeImmutable $now): self
    {
        $location = new self($establishment, $kind, $now);
        $location->code = self::code($code);
        $location->name = self::name($name);
        $location->parent = $location->placedUnder($parent);

        return $location;
    }

    /**
     * @return list<string> the fields that changed
     *
     * @throws InvalidStockLocation
     */
    public function revise(?self $parent, StockLocationKind $kind, string $code, string $name, \DateTimeImmutable $now): array
    {
        $code = self::code($code);
        $name = self::name($name);
        $parent = $this->placedUnder($parent);

        $changed = [];
        if ($parent?->id->toRfc4122() !== $this->parent?->id->toRfc4122()) {
            $changed[] = 'parentId';
        }
        if ($kind !== $this->kind) {
            $changed[] = 'kind';
        }
        if ($code !== $this->code) {
            $changed[] = 'code';
        }
        if ($name !== $this->name) {
            $changed[] = 'name';
        }
        if ([] !== $changed) {
            $this->parent = $parent;
            $this->kind = $kind;
            $this->code = $code;
            $this->name = $name;
            $this->updatedAt = $now;
        }

        return $changed;
    }

    private function placedUnder(?self $parent): ?self
    {
        if ($this->isDefault) {
            if (null !== $parent) {
                throw new InvalidStockLocation('parentId', 'The default location of an establishment is the top of its tree.');
            }

            return null;
        }
        if (null === $parent || !$parent->establishment->getId()->equals($this->establishment->getId())) {
            throw new InvalidStockLocation('parentId', 'A location sits under another location of its establishment.');
        }
        for ($above = $parent; null !== $above; $above = $above->parent) {
            if ($above->id->equals($this->id)) {
                throw new InvalidStockLocation('parentId', 'A location never sits under itself or under one of its own locations.');
            }
        }

        return $parent;
    }

    private static function code(string $code): string
    {
        $code = trim($code);
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidStockLocation('code', \sprintf('A location code is 1 to %d letters, digits, dots, dashes or underscores.', self::CODE_MAX));
        }

        return $code;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidStockLocation('name', \sprintf('A location is named in 1 to %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Where this location is drawn on a plan, or nowhere (docs/SPEC.md § 7, 2026-09-14). The rectangle belongs to the
     * venue; only the binding is the inventory's, which is what lets a dining room be drawn by a module that knows
     * nothing about stock. A location that is not drawn is still a location, so null is an ordinary answer.
     *
     * @return bool whether anything changed
     *
     * @throws InvalidStockLocation
     */
    public function drawAt(?VenueSpot $spot, \DateTimeImmutable $now): bool
    {
        if ($spot?->getId()->toRfc4122() === $this->spot?->getId()->toRfc4122()) {
            return false;
        }
        if (null !== $spot && !$spot->getArea()->getEstablishment()->getId()->equals($this->establishment->getId())) {
            throw new InvalidStockLocation('spotId', 'A location is drawn on a floor of its own establishment.');
        }
        $this->spot = $spot;
        $this->updatedAt = $now;

        return true;
    }

    public function getSpot(): ?VenueSpot
    {
        return $this->spot;
    }

    public function getKind(): StockLocationKind
    {
        return $this->kind;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
