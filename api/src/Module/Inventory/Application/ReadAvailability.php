<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What the customer screen may say of stock: in or out, a yes or a no and never a quantity, for the goods whose stock
 * the company keeps, and only once the company, or the establishment itself, has turned it on. The screen stands at
 * one establishment and says that establishment's own shelves; a screen that names none stands at the main one, so
 * the whole company's stock is never what a customer reads.
 */
final readonly class ReadAvailability
{
    public function __construct(
        private StockMovementRepository $movements,
        private ProductRepository $products,
        private KeepStock $stock,
        private ReadSetting $settings,
        private EstablishmentRepository $establishments,
    ) {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{productId: string, inStock: bool}> empty while the establishment keeps the answer to itself
     *
     * @throws EstablishmentNotFound when the establishment named is not the company's
     */
    public function among(Company $company, array $productIds, ?Uuid $establishmentId = null): array
    {
        $establishment = $this->establishmentOf($company, $establishmentId);
        if (true !== $this->settings->value(new SettingContext($company, establishmentId: $establishment), CustomerScreenSettings::SHOW_STOCK)) {
            return [];
        }
        $kept = array_values(array_filter($this->products->ofIdsInCompany($productIds, $company->getId()), $this->stock->tracked(...)));
        $totals = $this->movements->totalsOf($company->getId(), array_map(static fn ($product): Uuid => $product->getId(), $kept), $establishment);
        $rows = [];
        foreach ($kept as $product) {
            $id = $product->getId()->toRfc4122();
            $rows[] = ['productId' => $id, 'inStock' => 1 === new Number($totals[$id] ?? '0')->compare(0)];
        }

        return $rows;
    }

    /** @throws EstablishmentNotFound */
    private function establishmentOf(Company $company, ?Uuid $establishmentId): Uuid
    {
        if (null !== $establishmentId) {
            return $this->establishments->ofIdInCompany($establishmentId, $company->getId())?->getId()
                ?? throw new EstablishmentNotFound('No such establishment.');
        }
        foreach ($this->establishments->ofCompany($company->getId()) as $establishment) {
            if ($establishment->isDefault()) {
                return $establishment->getId();
            }
        }

        throw new \LogicException('A company always has its main establishment.');
    }
}
