<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What an expense's bill charges of one tax: a rate on its base, or a fixed charge such as the stamp. The amount is
 * the one the bill prints, which may differ from the worked-out one by the supplier's rounding; both are kept.
 * Amounts with three decimals.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense_tax')]
#[ORM\Index(name: 'idx_expense_tax_expense', columns: ['expense_id'])]
#[ORM\Index(name: 'idx_expense_tax_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_expense_tax_tax_component', columns: ['tax_component_id'])]
class ExpenseTax implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Expense::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'expense_id', nullable: false, onDelete: 'CASCADE')]
    private Expense $expense;

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

    #[ORM\Column(length: 32, enumType: TaxKind::class)]
    private TaxKind $kind;

    /** A percentage with three decimals; null for a fixed charge. */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $rate;

    #[ORM\Column]
    private bool $entersVatBase;

    /** What the rate is taken on; null for a fixed charge. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $base;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $workedOutAmount;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amount;

    /** @internal an expense writes its taxes */
    public function __construct(Expense $expense, int $position, TaxComponent $taxComponent, ?string $rate, ?string $base, string $workedOutAmount, string $amount)
    {
        $this->id = Uuid::v7();
        $this->expense = $expense;
        $this->company = $expense->getCompany();
        $this->position = $position;
        $this->taxComponent = $taxComponent;
        $this->code = $taxComponent->getCode();
        $this->kind = $taxComponent->getKind();
        $this->rate = $rate;
        $this->entersVatBase = $taxComponent->entersVatBase();
        $this->base = $base;
        $this->workedOutAmount = $workedOutAmount;
        $this->amount = $amount;
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

    public function getKind(): TaxKind
    {
        return $this->kind;
    }

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }

    public function getBase(): ?string
    {
        return $this->base;
    }

    public function getWorkedOutAmount(): string
    {
        return $this->workedOutAmount;
    }

    /** What the bill charges: the printed amount when one was typed, else the worked-out one. */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /** Whether the amount is the one the bill prints rather than the worked-out one. */
    public function isPrinted(): bool
    {
        return $this->amount !== $this->workedOutAmount;
    }
}
