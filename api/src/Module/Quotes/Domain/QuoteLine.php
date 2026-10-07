<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One line of a quote, written by its quote and never on its own. */
#[ORM\Entity]
#[ORM\Table(name: 'quote_line')]
#[ORM\Index(name: 'idx_quote_line_quote', columns: ['quote_id'])]
#[ORM\Index(name: 'idx_quote_line_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_quote_line_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_quote_line_unit', columns: ['unit_id'])]
class QuoteLine implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'quote_id', nullable: false, onDelete: 'CASCADE')]
    private Quote $quote;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: true)]
    private ?Product $product;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', nullable: false)]
    private Unit $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $unitPriceNet;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $discountRate;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $discountAmount;

    /** @var Collection<int, QuoteLineTax> */
    #[ORM\OneToMany(targetEntity: QuoteLineTax::class, mappedBy: 'line', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /** @internal a line is written by Quote */
    public function __construct(Quote $quote, int $position, QuoteLineDetails $details)
    {
        $this->id = Uuid::v7();
        $this->quote = $quote;
        $this->company = $quote->getCompany();
        $this->position = $position;
        $this->product = $details->product;
        $this->description = $details->description;
        $this->quantity = $details->quantity;
        $this->unit = $details->unit;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->discountRate = $details->discountRate;
        $this->discountAmount = $details->discountAmount;
        $this->taxes = new ArrayCollection();
        foreach ($details->taxes as $index => $tax) {
            $this->taxes->add(new QuoteLineTax($this, $index + 1, $tax));
        }
    }

    /**
     * @internal sending checks the quantity against its unit as the unit counts today: a unit's decimals may have been
     * lowered since the line was written
     *
     * @throws InvalidQuote
     */
    public function assertCountedByItsUnit(): void
    {
        [, $decimals] = [...explode('.', $this->quantity), ''];
        if (\strlen(rtrim($decimals, '0')) > $this->unit->getDecimals()) {
            throw new InvalidQuote('quantity', \sprintf('Line %d offers %s, but the unit %s now counts with %d decimals: revise the line before sending.', $this->position, $this->quantity, $this->unit->getCode(), $this->unit->getDecimals()));
        }
    }

    /** @internal sending takes each tax's code, rate and VAT base behaviour again, as they stand on the issue day */
    public function retakeTaxes(): void
    {
        foreach ($this->taxes as $tax) {
            $tax->retake();
        }
    }

    /** @return array{string|null, string, string, string, string, string|null, list<string>, string|null} compared the way QuoteLineDetails::values() is */
    public function values(): array
    {
        return [
            $this->product?->getId()->toRfc4122(),
            $this->description,
            $this->quantity,
            $this->unit->getId()->toRfc4122(),
            $this->unitPriceNet,
            $this->discountRate,
            array_map(static fn (QuoteLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $this->getTaxes()),
            $this->discountAmount,
        ];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getUnitPriceNet(): string
    {
        return $this->unitPriceNet;
    }

    /** A percentage with three decimals; null for no discount. */
    public function getDiscountRate(): ?string
    {
        return $this->discountRate;
    }

    /** The line's whole discount as an amount, with three decimals; null for none, or one given as a rate. */
    public function getDiscountAmount(): ?string
    {
        return $this->discountAmount;
    }

    /** @return list<QuoteLineTax> in the order they are printed */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
