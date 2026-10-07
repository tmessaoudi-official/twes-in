<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax a quote line carries, with the code, rate and VAT base behaviour the component had when the line was written,
 * taken again when the quote is sent: a company editing its tax later changes no sent quote.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quote_line_tax')]
#[ORM\Index(name: 'idx_quote_line_tax_line', columns: ['line_id'])]
#[ORM\Index(name: 'idx_quote_line_tax_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_quote_line_tax_component', columns: ['tax_component_id'])]
class QuoteLineTax implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: QuoteLine::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
    private QuoteLine $line;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: TaxComponent::class)]
    #[ORM\JoinColumn(name: 'tax_component_id', nullable: false)]
    private TaxComponent $taxComponent;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $rate;

    #[ORM\Column]
    private bool $entersVatBase;

    /** @internal a line's taxes are written by its line */
    public function __construct(QuoteLine $line, int $position, TaxComponent $taxComponent)
    {
        $this->id = Uuid::v7();
        $this->line = $line;
        $this->company = $line->getCompany();
        $this->position = $position;
        $this->taxComponent = $taxComponent;
        $this->retake();
    }

    /** @internal the code, rate and VAT base behaviour its component has now */
    public function retake(): void
    {
        $rate = $this->taxComponent->getRate() ?? throw new \LogicException(\sprintf('The line tax %s has no rate.', $this->taxComponent->getCode()));
        $this->code = $this->taxComponent->getCode();
        $this->rate = Decimal::format(Decimal::of($rate), 3);
        $this->entersVatBase = $this->taxComponent->entersVatBase();
    }

    public function getLine(): QuoteLine
    {
        return $this->line;
    }

    public function getTaxComponent(): TaxComponent
    {
        return $this->taxComponent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /** A percentage with three decimals, as it stood when the line was written or the quote sent. */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
