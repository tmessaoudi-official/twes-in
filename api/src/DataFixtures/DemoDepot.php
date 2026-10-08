<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Tenancy\Domain\Company;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\StructureKind;
use Symfony\Component\Uid\Uuid;

/**
 * A demo company's depot, drawn through the stock map's own use cases, so the map opens on a place rather than on
 * « Aucun étage »: one floor of 24 × 14 m, a rack per family of goods along an aisle, a reception and a dispatch zone,
 * and the building around them (four walls, a door, a post, a dock). The racks are returned so the goods can make
 * them their homes and be received there.
 */
final readonly class DemoDepot
{
    /** As many racks as the floor holds side by side along its aisle; any further family shares the last one. */
    private const int MOST_RACKS = 8;

    public function __construct(
        private ManageStockLocations $locations,
        private DrawStockMap $map,
        private ArrangeVenue $venue,
    ) {
    }

    /**
     * @param list<string> $families the product categories that hold goods, in the order their racks stand
     *
     * @return array<string, Uuid> each family's rack
     */
    public function draw(Company $company, Uuid $establishmentId, array $families, Uuid $actor): array
    {
        $floor = $this->map->addFloor($company, $establishmentId, 'Rez-de-chaussée', 0, '24', '14', $actor)->getId();

        $racks = [];
        foreach (\array_slice($families, 0, self::MOST_RACKS) as $n => $family) {
            $rack = $this->locations->create($company, $establishmentId, null, StockLocationKind::Rack, 'R'.($n + 1), $family, $actor)->getId();
            $this->map->draw($company, $floor, $rack, new PlanRect(bcadd('2', bcmul((string) $n, '2.6', 1), 1), '2', '1.2', '6', 0, '2.4'), $actor);
            $racks[] = $rack;
        }
        foreach ([['RECEPTION', 'Réception', '1'], ['EXPEDITION', 'Expédition', '17']] as [$code, $name, $x]) {
            $zone = $this->locations->create($company, $establishmentId, null, StockLocationKind::Zone, $code, $name, $actor)->getId();
            $this->map->draw($company, $floor, $zone, new PlanRect($x, '10', '6', '3', 0, '0'), $actor);
        }
        foreach ([
            [StructureKind::Wall, 'Mur nord', new PlanRect('0', '0', '24', '0.2', 0, '3')],
            [StructureKind::Wall, 'Mur sud', new PlanRect('0', '13.8', '24', '0.2', 0, '3')],
            [StructureKind::Wall, 'Mur ouest', new PlanRect('0', '0', '0.2', '14', 0, '3')],
            [StructureKind::Wall, 'Mur est', new PlanRect('23.8', '0', '0.2', '14', 0, '3')],
            [StructureKind::Door, 'Entrée', new PlanRect('0', '10.75', '0.2', '1.5', 0, '2.1')],
            [StructureKind::Post, 'Poteau', new PlanRect('11.85', '9', '0.3', '0.3', 0, '3')],
            [StructureKind::Dock, 'Quai', new PlanRect('18', '13.8', '4', '0.2', 0, '3')],
        ] as [$kind, $name, $rect]) {
            $this->venue->build($company, $floor, $kind, $name, $rect, $actor);
        }

        $homes = [];
        foreach ($families as $n => $family) {
            $homes[$family] = $racks[min($n, \count($racks) - 1)];
        }

        return $homes;
    }
}
