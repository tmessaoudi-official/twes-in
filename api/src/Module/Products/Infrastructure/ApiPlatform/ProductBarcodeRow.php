<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductBarcode;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One code of a product as the API reads and writes it (docs/SPEC.md § 7, 2026-09-22 11:05). Embedded, never a
 * resource of its own: a product's codes are written together, as one list (`ProductBarcodesResource`).
 */
final class ProductBarcodeRow
{
    /** `unit` enters one piece, `pack` several, `supplier` is a supplier's own code, `internal` one the company printed. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['unit', 'pack', 'supplier', 'internal']])]
    #[Assert\Choice(choices: ['unit', 'pack', 'supplier', 'internal'], groups: [ProductBarcodesResource::WRITE])]
    #[Groups([ProductResource::READ, ProductBarcodesResource::READ, ProductBarcodesResource::WRITE])]
    public string $role = 'unit';

    /** As printed; an EAN, UPC or GTIN-14 has its check digit verified. */
    #[Assert\NotBlank(groups: [ProductBarcodesResource::WRITE])]
    #[Assert\Length(max: Barcode::MAX, groups: [ProductBarcodesResource::WRITE])]
    #[Groups([ProductResource::READ, ProductBarcodesResource::READ, ProductBarcodesResource::WRITE])]
    public string $code = '';

    /** How many pieces one scan of it enters: 1 for a unit code, more for a pack. */
    #[Assert\Range(min: 1, max: BarcodeLine::QUANTITY_MAX, groups: [ProductBarcodesResource::WRITE])]
    #[Groups([ProductResource::READ, ProductBarcodesResource::READ, ProductBarcodesResource::WRITE])]
    public int $quantity = 1;

    /** The supplier who prints it (GET .../vendors), for a supplier's code only. */
    #[Assert\Uuid(groups: [ProductBarcodesResource::WRITE])]
    #[Groups([ProductResource::READ, ProductBarcodesResource::READ, ProductBarcodesResource::WRITE])]
    public ?string $supplierId = null;

    public static function of(ProductBarcode $row): self
    {
        $resource = new self();
        $resource->role = $row->getRole()->value;
        $resource->code = $row->getCode();
        $resource->quantity = $row->getQuantity();
        $resource->supplierId = $row->getSupplier()?->getId()->toRfc4122();

        return $resource;
    }

    /**
     * A product's codes as a caller reads them: a supplier's codes only with product.cost.read.
     *
     * @return list<self>
     */
    public static function listOf(Product $product, bool $withCosts): array
    {
        $rows = [];
        foreach ($product->getBarcodes() as $row) {
            if ($withCosts || BarcodeRole::Supplier !== $row->getRole()) {
                $rows[] = self::of($row);
            }
        }

        return $rows;
    }

    public function input(): BarcodeInput
    {
        return new BarcodeInput($this->role, $this->code, $this->quantity, null === $this->supplierId ? null : Uuid::fromString($this->supplierId));
    }
}
