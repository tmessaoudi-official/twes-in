<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One piece of the building drawn on one floor — a wall, a door, a post, a dock (the approved canvas's Structure
 * board, docs/SPEC.md row 83).
 *
 * It is its own table and not a `VenueSpot`, because a spot exists to be BOUND to: a stock location points at one,
 * and a dining room's table will point at one the same way. Nothing ever binds itself to a wall. Were the building
 * drawn as spots bound to stock locations, every wall would appear in every stock list, every import and every
 * movement's location picker, to hold nothing for as long as the company exists — which is the canvas's own reason
 * for a separate layer, in its own words.
 *
 * The rectangle is the same `PlanRect` the rest of the plan is measured in, so a wall is dragged, resized and
 * turned by the gestures already written rather than by a second set that would drift from them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_structure')]
#[ORM\Index(name: 'idx_venue_structure_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_venue_structure_area', columns: ['area_id'])]
class VenueStructure implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: VenueArea::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private VenueArea $area;

    #[ORM\Column(length: 16, enumType: StructureKind::class)]
    private StructureKind $kind;

    #[ORM\Column(name: 'plan_x', type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE)]
    private string $x;

    #[ORM\Column(name: 'plan_y', type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE)]
    private string $y;

    #[ORM\Column(name: 'plan_width', type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE)]
    private string $width;

    #[ORM\Column(name: 'plan_depth', type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE)]
    private string $depth;

    #[ORM\Column(name: 'plan_rotation')]
    private int $rotation;

    #[ORM\Column(name: 'plan_height', type: Types::DECIMAL, precision: 9, scale: PlanRect::SCALE)]
    private string $height;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(VenueArea $area, StructureKind $kind, PlanRect $rect, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $area->getCompany();
        $this->area = $area;
        $this->kind = $kind;
        $this->write($rect);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function build(VenueArea $area, StructureKind $kind, PlanRect $rect, \DateTimeImmutable $now): self
    {
        return new self($area, $kind, $rect, $now);
    }

    /**
     * Corrects what this piece is and where it stands. A piece never changes floor, for the reason a spot never
     * does: a floor is a floor, and the same wall one storey up is another wall.
     *
     * The kind is part of the comparison and not only the rectangle: a gap traced with the wall tool is right in
     * every measurement and wrong in exactly one field, and a comparison that read the rectangle alone would answer
     * "nothing changed" and leave it a wall.
     *
     * @return bool whether anything changed
     */
    public function reshape(StructureKind $kind, PlanRect $rect, \DateTimeImmutable $now): bool
    {
        if ($kind === $this->kind && $rect->equals($this->getRect())) {
            return false;
        }
        $this->kind = $kind;
        $this->write($rect);
        $this->updatedAt = $now;

        return true;
    }

    public function getRect(): PlanRect
    {
        return new PlanRect($this->x, $this->y, $this->width, $this->depth, $this->rotation, $this->height);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getArea(): VenueArea
    {
        return $this->area;
    }

    public function getKind(): StructureKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function write(PlanRect $rect): void
    {
        $this->x = $rect->x;
        $this->y = $rect->y;
        $this->width = $rect->width;
        $this->depth = $rect->depth;
        $this->rotation = $rect->rotation;
        $this->height = $rect->height;
    }
}
