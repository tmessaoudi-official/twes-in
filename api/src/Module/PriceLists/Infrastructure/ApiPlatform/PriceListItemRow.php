<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\ApiPlatform;

use App\Module\PriceLists\Application\PriceListItemInput;
use App\Module\PriceLists\Domain\PriceListItem;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One price of a list as the API reads and writes it: a product, the quantity it applies from and the net unit price.
 * Embedded, never a resource of its own: a list's prices are written together with it (`PriceListResource`). The
 * quantity and the price are decimal strings, so no float ever carries money.
 */
final class PriceListItemRow
{
    #[Assert\NotBlank(groups: [PriceListResource::WRITE])]
    #[Assert\Uuid(groups: [PriceListResource::WRITE])]
    #[Groups([PriceListResource::READ, PriceListResource::WRITE])]
    public string $productId = '';

    /** The product's reference and name, for a screen to show what the row is without a second request. */
    #[Groups([PriceListResource::READ])]
    public string $productReference = '';

    #[Groups([PriceListResource::READ])]
    public string $productName = '';

    /** From this quantity up, with at most three decimals. */
    #[Assert\NotBlank(groups: [PriceListResource::WRITE])]
    #[Assert\Type('string', groups: [PriceListResource::WRITE])]
    #[Groups([PriceListResource::READ, PriceListResource::WRITE])]
    public string $minQuantity = '1';

    /** Net of tax, with at most four decimals. */
    #[Assert\NotBlank(groups: [PriceListResource::WRITE])]
    #[Assert\Type('string', groups: [PriceListResource::WRITE])]
    #[Groups([PriceListResource::READ, PriceListResource::WRITE])]
    public string $unitPriceNet = '0';

    public static function of(PriceListItem $item): self
    {
        $row = new self();
        $row->productId = $item->getProduct()->getId()->toRfc4122();
        $row->productReference = $item->getProduct()->getReference();
        $row->productName = $item->getProduct()->getDetails()->name;
        $row->minQuantity = $item->getMinQuantity();
        $row->unitPriceNet = $item->getUnitPriceNet();

        return $row;
    }

    public function input(): PriceListItemInput
    {
        return new PriceListItemInput(Uuid::fromString($this->productId), $this->minQuantity, $this->unitPriceNet);
    }
}
