<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a company files its expenses under (docs/SPEC.md § 4 expense_category), in a tree: a category may sit under
 * another of its company, never under itself or one of its own subcategories. Expenses keep their category, so a
 * category is deactivated, never deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense_category')]
#[ORM\Index(name: 'idx_expense_category_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_expense_category_parent', columns: ['parent_id'])]
#[ORM\UniqueConstraint(name: 'uniq_expense_category_company_name', columns: ['company_id', 'name'])]
class ExpenseCategory
{
    public const int NAME_MAX = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?ExpenseCategory $parent = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidExpense */
    public static function create(Company $company, string $name, ?self $parent, \DateTimeImmutable $now): self
    {
        $category = new self($company, $now);
        $category->name = self::name($name);
        $category->parent = $category->parentOfThisCompany($parent);

        return $category;
    }

    /**
     * @return list<string> the fields that changed
     *
     * @throws InvalidExpense
     */
    public function revise(string $name, ?self $parent, bool $isActive, \DateTimeImmutable $now): array
    {
        $name = self::name($name);
        $parent = $this->parentOfThisCompany($parent);
        for ($above = $parent; null !== $above; $above = $above->parent) {
            if ($above->id->equals($this->id)) {
                throw new InvalidExpense('parentId', 'A category never sits under itself or under one of its own subcategories.');
            }
        }

        $changed = $name === $this->name ? [] : ['name'];
        if ($parent?->id->toRfc4122() !== $this->parent?->id->toRfc4122()) {
            $changed[] = 'parentId';
        }
        if ($isActive !== $this->isActive) {
            $changed[] = 'isActive';
        }
        if ([] !== $changed) {
            $this->name = $name;
            $this->parent = $parent;
            $this->isActive = $isActive;
            $this->updatedAt = $now;
        }

        return $changed;
    }

    private function parentOfThisCompany(?self $parent): ?self
    {
        if (null !== $parent && !$parent->company->getId()->equals($this->company->getId())) {
            throw new InvalidExpense('parentId', 'A category sits under a category of its own company.');
        }

        return $parent;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidExpense('name', \sprintf('An expense category is named in 1 to %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
