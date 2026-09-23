<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductTracking;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One lot of a product tracked by lot, or one piece of a product tracked by serial number (docs/SPEC.md § 7,
 * 2026-09-23 02:40): the code printed on the goods, unique within the product, and the date they are used by when
 * they have one. A lot is opened the first time goods arrive under its code; its stock is the sum of the movements
 * naming it, as any stock here is.
 *
 * A code is 1 to 40 printable ASCII characters without a space: a GS1 lot, `(10)`, is at most 20 of them from that
 * set, and a company's own codes run longer. A letter outside ASCII is refused rather than kept, because a scanner
 * reads a label's code as ASCII and a code nobody can scan back finds nothing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_lot')]
#[ORM\Index(name: 'idx_stock_lot_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_lot_code', columns: ['product_id', 'code'])]
class StockLot implements CompanyOwned
{
    public const int CODE_MAX = 40;

    private const string CODE = '/^[\x21-\x7E]{1,40}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private Product $product;

    #[ORM\Column(length: self::CODE_MAX)]
    private string $code;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(Product $product, string $code, ?\DateTimeImmutable $expiresOn, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->code = $code;
        $this->expiresOn = self::day($expiresOn);
        $this->createdAt = $now;
    }

    /** @throws InvalidStockMovement */
    public static function open(Product $product, string $code, ?\DateTimeImmutable $expiresOn, \DateTimeImmutable $now): self
    {
        if (ProductTracking::None === $product->getTracking()) {
            throw new InvalidStockMovement('lot', \sprintf('The product %s is not tracked by lot or serial number: its stock names no lot.', $product->getReference()));
        }

        return new self($product, self::code($code), $expiresOn, $now);
    }

    /**
     * The code a person or a scanner gave, trimmed; refused when it is not one a label can carry.
     *
     * @throws InvalidStockMovement
     */
    public static function code(string $code): string
    {
        $code = trim($code);
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidStockMovement('lotCode', \sprintf('A lot code is 1 to %d printable characters without space or accent.', self::CODE_MAX));
        }

        return $code;
    }

    /**
     * The date goods arriving under this lot say they are used by. A lot opened without one takes it; a lot that has
     * one keeps it, and a different day is refused, since one lot is one batch and has one date.
     *
     * @return bool whether the lot took a date it did not have
     *
     * @throws InvalidStockMovement
     */
    public function dated(?\DateTimeImmutable $expiresOn): bool
    {
        $day = self::day($expiresOn);
        if (null === $day || $day == $this->expiresOn) {
            return false;
        }
        if (null !== $this->expiresOn) {
            throw new InvalidStockMovement('lotExpiresOn', \sprintf('The lot %s of %s is used by %s: one lot has one date.', $this->code, $this->product->getReference(), $this->expiresOn->format('Y-m-d')));
        }
        $this->expiresOn = $day;

        return true;
    }

    private static function day(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        return $date?->setTime(0, 0);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getExpiresOn(): ?\DateTimeImmutable
    {
        return $this->expiresOn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
