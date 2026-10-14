<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Fiscal\Domain\TaxComponent;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One block of an expense's bill: a net and the rates on it, written by the expense as the bill prints it. */
#[ORM\Entity]
#[ORM\Table(name: 'expense_line')]
#[ORM\Index(name: 'idx_expense_line_expense', columns: ['expense_id'])]
#[ORM\Index(name: 'idx_expense_line_company', columns: ['company_id'])]
class ExpenseLine implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Expense::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'expense_id', nullable: false, onDelete: 'CASCADE')]
    private Expense $expense;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amountNet;

    /** @var Collection<int, ExpenseLineTax> */
    #[ORM\OneToMany(targetEntity: ExpenseLineTax::class, mappedBy: 'line', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /**
     * @internal an expense writes its lines
     *
     * @param list<TaxComponent> $taxes
     */
    public function __construct(Expense $expense, int $position, string $amountNet, array $taxes)
    {
        $this->id = Uuid::v7();
        $this->expense = $expense;
        $this->company = $expense->getCompany();
        $this->position = $position;
        $this->amountNet = $amountNet;
        $this->taxes = new ArrayCollection();
        foreach ($taxes as $at => $tax) {
            $this->taxes->add(new ExpenseLineTax($this, $at, $tax));
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** Three decimals. */
    public function getAmountNet(): string
    {
        return $this->amountNet;
    }

    /** @return list<ExpenseLineTax> in the order the bill prints them */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    /** @return list<string> */
    public function getTaxCodes(): array
    {
        return array_map(static fn (ExpenseLineTax $tax): string => $tax->getCode(), $this->getTaxes());
    }
}
