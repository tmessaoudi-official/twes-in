<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A rate on one block of an expense's bill, with the code, rate and VAT base behaviour its component had when the bill
 * was written: a company editing its tax later changes no recorded expense.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense_line_tax')]
#[ORM\Index(name: 'idx_expense_line_tax_line', columns: ['line_id'])]
#[ORM\Index(name: 'idx_expense_line_tax_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_expense_line_tax_component', columns: ['tax_component_id'])]
class ExpenseLineTax implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ExpenseLine::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
    private ExpenseLine $line;

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

    /** @internal a line writes its taxes */
    public function __construct(ExpenseLine $line, int $position, TaxComponent $taxComponent)
    {
        $rate = $taxComponent->getRate() ?? throw new \LogicException(\sprintf('The line tax %s has no rate.', $taxComponent->getCode()));
        $this->id = Uuid::v7();
        $this->line = $line;
        $this->company = $line->getCompany();
        $this->position = $position;
        $this->taxComponent = $taxComponent;
        $this->code = $taxComponent->getCode();
        $this->rate = Decimal::format(Decimal::of($rate), 3);
        $this->entersVatBase = $taxComponent->entersVatBase();
    }

    public function getTaxComponent(): TaxComponent
    {
        return $this->taxComponent;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /** A percentage with three decimals. */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }
}
