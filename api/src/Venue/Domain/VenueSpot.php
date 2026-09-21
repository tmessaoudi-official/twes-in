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
 * One rectangle drawn on one area (docs/SPEC.md § 4 venue_spot; § 7, 2026-09-14). A spot knows WHERE it is and
 * nothing about what it holds: a stock location points at it, and a café table will point at one the same way, so a
 * company draws a storeroom and a dining room side by side and neither domain owns the drawing.
 *
 * It therefore carries no code, no name and no kind — those belong to whatever bound itself to it, which is also
 * what the screen labels the rectangle with.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_spot')]
#[ORM\Index(name: 'idx_venue_spot_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_venue_spot_area', columns: ['area_id'])]
class VenueSpot implements CompanyOwned
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

    private function __construct(VenueArea $area, PlanRect $rect, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $area->getCompany();
        $this->area = $area;
        $this->write($rect);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function place(VenueArea $area, PlanRect $rect, \DateTimeImmutable $now): self
    {
        return new self($area, $rect, $now);
    }

    /**
     * A spot never changes area: an area is one floor, and goods do not climb. Drawing the same rack on another
     * floor is drawing another rack, which is why this takes a rectangle and nothing else.
     *
     * @return bool whether anything changed
     */
    public function moveTo(PlanRect $rect, \DateTimeImmutable $now): bool
    {
        if ($rect->equals($this->getRect())) {
            return false;
        }
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
