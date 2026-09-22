<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One drawable floor of an establishment (docs/SPEC.md § 4 venue_area; § 7, 2026-09-14): the ground floor, an upper
 * floor, a mezzanine. The area IS the canvas — a site or a building is never drawn, only the floor is — and what
 * stands on it are spots, which consumers bind to their own records.
 *
 * A floor plan behind the drawing is optional and is a file like any other. Two things are kept with it: how opaque
 * to show it, and how many metres wide the image really is, which is the only honest way to place a rectangle in
 * metres over a photograph nobody measured.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_area')]
#[ORM\Index(name: 'idx_venue_area_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_venue_area_establishment', columns: ['establishment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_venue_area_establishment_level', columns: ['establishment_id', 'level'])]
class VenueArea implements CompanyOwned
{
    public const int NAME_MAX = 120;
    /** Deep enough for any basement a business draws, and bounded so an ordering column cannot run away. */
    public const int LEVEL_MIN = 0;
    public const int LEVEL_MAX = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, onDelete: 'CASCADE')]
    private Establishment $establishment;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** Which floor, from the ground up; it orders the tabs and stacks the 3D view. */
    #[ORM\Column]
    private int $level;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $imageFileId = null;

    /** How much of the plan shows through, 0 to 100: a scan is a guide, never the drawing. */
    #[ORM\Column(options: ['default' => 35])]
    private int $imageOpacity = 35;

    /** What the whole image spans on the ground, in metres — the scale the drawing is placed against. */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE, nullable: true)]
    private ?string $imageMetresWide = null;

    /**
     * The floor's own size in metres, which the board frames and outlines. Null on a floor drawn before it was asked
     * (docs/SPEC.md § 7, 2026-09-22, findings E and H): the board then frames what is drawn on it.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE, nullable: true)]
    private ?string $widthMetres = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE, nullable: true)]
    private ?string $depthMetres = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Establishment $establishment, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $establishment->getCompany();
        $this->establishment = $establishment;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidVenue */
    public static function create(Establishment $establishment, string $name, int $level, \DateTimeImmutable $now): self
    {
        $area = new self($establishment, $now);
        $area->name = self::name($name);
        $area->level = self::level($level);

        return $area;
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidVenue
     */
    public function rename(string $name, int $level, \DateTimeImmutable $now): bool
    {
        $name = self::name($name);
        $level = self::level($level);
        if ($name === $this->name && $level === $this->level) {
            return false;
        }
        $this->name = $name;
        $this->level = $level;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * The plan behind the drawing, or none. Its width in metres comes with it: without a scale an image cannot be
     * placed under rectangles measured in metres, so the two are set together or not at all.
     *
     * @return bool whether anything changed
     *
     * @throws InvalidVenue
     */
    public function showPlan(?Uuid $imageFileId, ?string $metresWide, int $opacity, \DateTimeImmutable $now): bool
    {
        if ($opacity < 0 || $opacity > 100) {
            throw new InvalidVenue('imageOpacity', 'An opacity is a whole number from 0 to 100.');
        }
        $width = null;
        if (null !== $imageFileId) {
            if (null === $metresWide) {
                throw new InvalidVenue('imageMetresWide', 'A plan is placed by saying how many metres wide it really is.');
            }
            // A rectangle of that width is exactly what the scale means, so the same rule measures it.
            $width = new PlanRect('0', '0', $metresWide, '0.001', 0, '0')->width;
        }
        // Measured after normalizing, so re-saving an unchanged form says nothing: it is the same plan at the same
        // scale, and an audit row for it would tell every other open plan to read itself again for nothing.
        if ([$imageFileId?->toRfc4122(), $width, $opacity] === [$this->imageFileId?->toRfc4122(), $this->imageMetresWide, $this->imageOpacity]) {
            return false;
        }
        $this->imageFileId = $imageFileId;
        $this->imageMetresWide = $width;
        $this->imageOpacity = $opacity;
        $this->updatedAt = $now;

        return true;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getImageFileId(): ?Uuid
    {
        return $this->imageFileId;
    }

    public function getImageOpacity(): int
    {
        return $this->imageOpacity;
    }

    public function getImageMetresWide(): ?string
    {
        return $this->imageMetresWide;
    }

    public function getWidthMetres(): ?string
    {
        return $this->widthMetres;
    }

    public function getDepthMetres(): ?string
    {
        return $this->depthMetres;
    }

    /**
     * The floor's width and depth, set together: a floor with one side is not a surface. Measured by the rule every
     * rectangle on it follows — more than zero, under the limit, at most three decimals — and refused on the side at
     * fault.
     *
     * @return bool whether anything changed
     *
     * @throws InvalidVenue
     */
    public function measure(string $width, string $depth, \DateTimeImmutable $now): bool
    {
        try {
            $size = new PlanRect('0', '0', $width, '0.001', 0, '0');
            $width = $size->width;
        } catch (InvalidVenue $refused) {
            throw new InvalidVenue('widthMetres', $refused->getMessage());
        }
        try {
            $depth = new PlanRect('0', '0', '0.001', $depth, 0, '0')->depth;
        } catch (InvalidVenue $refused) {
            throw new InvalidVenue('depthMetres', $refused->getMessage());
        }
        if ([$width, $depth] === [$this->widthMetres, $this->depthMetres]) {
            return false;
        }
        $this->widthMetres = $width;
        $this->depthMetres = $depth;
        $this->updatedAt = $now;

        return true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @throws InvalidVenue */
    private static function name(string $name): string
    {
        $trimmed = trim($name);
        if ('' === $trimmed || mb_strlen($trimmed) > self::NAME_MAX) {
            throw new InvalidVenue('name', \sprintf('An area is named in 1 to %d characters.', self::NAME_MAX));
        }

        return $trimmed;
    }

    /** @throws InvalidVenue */
    private static function level(int $level): int
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidVenue('level', \sprintf('A level is a whole number from %d to %d, the ground being %d.', self::LEVEL_MIN, self::LEVEL_MAX, self::LEVEL_MIN));
        }

        return $level;
    }
}
