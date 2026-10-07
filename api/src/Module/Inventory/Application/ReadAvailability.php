<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Domain\Company;
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
    public function __construct(private ReadOnHand $onHand, private ReadSetting $settings)
    {
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
        $establishment = $this->onHand->establishment($company, $establishmentId);
        if (true !== $this->settings->value(new SettingContext($company, establishmentId: $establishment), CustomerScreenSettings::SHOW_STOCK)) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'productId' => $row['product']->getId()->toRfc4122(),
            'inStock' => 1 === new Number($row['onHand'])->compare(0),
        ], $this->onHand->at($company, $establishment, $productIds));
    }
}
