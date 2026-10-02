<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Domain;

use App\Module\Products\Domain\Product;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One price of a list: a product's unit price, net of tax, from a quantity up (docs/SPEC.md § 7, 2026-09-20 price
 * lists). Two rows of one product with different minimums are its quantity breaks; the list holds a product once per
 * minimum. It belongs to its list and is written only through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_list_item')]
#[ORM\Index(name: 'idx_price_list_item_product', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_price_list_item_break', columns: ['price_list_id', 'product_id', 'min_quantity'])]
class PriceListItem implements CompanyOwned
{
    private const string PRICE = '/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,4}))?$/';
    private const string QUANTITY = '/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: PriceList::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'price_list_id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** @var numeric-string */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $minQuantity;

    /** @var numeric-string */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $unitPriceNet;

    /** @throws InvalidPriceList */
    public function __construct(PriceList $priceList, Product $product, string $minQuantity, string $unitPriceNet)
    {
        if (!$priceList->getCompany()->getId()->equals($product->getCompany()->getId())) {
            throw new InvalidPriceList('productId', 'A price list prices the products of its own company.');
        }
        $this->id = Uuid::v7();
        $this->company = $priceList->getCompany();
        $this->priceList = $priceList;
        $this->product = $product;
        $this->minQuantity = self::minimum($minQuantity);
        $this->unitPriceNet = self::price($unitPriceNet);
    }

    /**
     * @return numeric-string
     *
     * @throws InvalidPriceList
     */
    private static function minimum(string $quantity): string
    {
        $quantity = trim($quantity);
        if (1 !== preg_match(self::QUANTITY, $quantity, $match)) {
            throw new InvalidPriceList('minQuantity', 'A minimum quantity is a decimal number with at most three decimals.');
        }
        $normalized = $match[1].'.'.str_pad($match[2] ?? '', 3, '0');
        if (!is_numeric($normalized) || (float) $normalized <= 0.0) {
            throw new InvalidPriceList('minQuantity', 'A price applies from a quantity above zero.');
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     *
     * @throws InvalidPriceList
     */
    private static function price(string $price): string
    {
        $price = trim($price);
        if (1 !== preg_match(self::PRICE, $price, $match)) {
            throw new InvalidPriceList('unitPriceNet', 'A price is a decimal number from 0 to 9999999999.9999, with at most four decimals.');
        }
        $normalized = $match[1].'.'.str_pad($match[2] ?? '', 4, '0');
        if (!is_numeric($normalized)) {
            throw new \LogicException(\sprintf('The price %s was not normalized to a number.', $normalized));
        }

        return $normalized;
    }

    /**
     * Moves the price of this break, which is the only thing a row holds that its list may change: its product and
     * minimum are its identity (the unique key), so a different one is another row.
     *
     * @return bool whether the price changed
     */
    public function reprice(string $unitPriceNet): bool
    {
        $price = self::price($unitPriceNet);
        if ($price === $this->unitPriceNet) {
            return false;
        }
        $this->unitPriceNet = $price;

        return true;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    /** @return numeric-string */
    public function getMinQuantity(): string
    {
        return $this->minQuantity;
    }

    /** @return numeric-string */
    public function getUnitPriceNet(): string
    {
        return $this->unitPriceNet;
    }
}
