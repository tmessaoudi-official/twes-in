<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Expenses\Domain\ExpenseCategory;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's expense categories, a tree listed flat by name, each row naming its parent. Read with expense.read,
 * added and revised with expense.write; never deleted, deactivated instead. A parent that would make a cycle answers
 * 422, a name another category has 409.
 */
#[ApiResource(
    shortName: 'ExpenseCategory',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/expense-categories',
            provider: ExpenseCategoryCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/expense-categories',
            processor: CreateExpenseCategoryProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/expense-categories/{categoryId}',
            processor: ReviseExpenseCategoryProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class ExpenseCategoryResource
{
    public const string READ = 'expense_category:read';
    public const string WRITE = 'expense_category:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: ExpenseCategory::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /** The category this one sits under; null at the top. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $parentId = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    public static function of(ExpenseCategory $category): self
    {
        $resource = new self();
        $resource->id = $category->getId()->toRfc4122();
        $resource->name = $category->getName();
        $resource->parentId = $category->getParent()?->getId()->toRfc4122();
        $resource->isActive = $category->isActive();

        return $resource;
    }
}
