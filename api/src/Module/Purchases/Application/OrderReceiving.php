<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Module\Purchases\Domain\Model\PurchaseOrderLine;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Where the goods of an order are offered to go: the places of its establishment, and for each line the place it goes
 * to unless the person says otherwise, its product's home there, else the establishment's own place, which is where
 * goods wait when nobody has said where they live. With them, whether what the receipt's cost does to the products'
 * cost is the person's to say, or the company's.
 */
final readonly class OrderReceiving
{
    public function __construct(private ManagePurchaseOrders $orders, private ReceivingPlaces $places, private StockIntake $stock)
    {
    }

    /**
     * @return array{places: list<ReceivingPlace>, homes: array<string, string>, asksCostBasis: bool} each line's place by order line id
     *
     * @throws PurchaseOrderNotFound
     */
    public function of(Company $company, Uuid $orderId): array
    {
        $order = $this->orders->get($company, $orderId);
        $establishmentId = $order->getEstablishment()->getId();
        $places = $this->places->placesOf($company, $establishmentId);
        $own = array_find($places, static fn (ReceivingPlace $place): bool => $place->isDefault)?->id;
        $lines = $order->getLines();
        $named = $this->places->homesOf($company, $establishmentId, array_map(static fn (PurchaseOrderLine $line): Uuid => $line->getProductId(), $lines));

        $homes = [];
        foreach ($lines as $line) {
            $home = $named[$line->getProductId()->toRfc4122()] ?? $own;
            if (null !== $home) {
                $homes[$line->getId()->toRfc4122()] = $home->toRfc4122();
            }
        }

        return ['places' => $places, 'homes' => $homes, 'asksCostBasis' => $this->stock->leavesCostToWhoReceives($company)];
    }
}
