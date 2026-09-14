<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Products\Domain\ProductCategory;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's product categories, a tree listed flat by name: each row names its parent and says how many
 * subcategories and products it holds. Read with product.read, changed with product.write; a category still holding
 * either answers 409 to a DELETE, and a parent that would make a cycle answers 422.
 */
#[ApiResource(
    shortName: 'ProductCategory',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/product-categories',
            provider: ProductCategoryCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/product-categories',
            processor: CreateProductCategoryProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/product-categories/{categoryId}',
            processor: ReviseProductCategoryProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/product-categories/{categoryId}',
            processor: DeleteProductCategoryProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ProductCategoryResource
{
    public const string READ = 'product_category:read';
    public const string WRITE = 'product_category:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: ProductCategory::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /** The category this one sits under; null at the top. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $parentId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $productCount = 0;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $childCount = 0;

    public static function of(ProductCategory $category, int $productCount, int $childCount): self
    {
        $resource = new self();
        $resource->id = $category->getId()->toRfc4122();
        $resource->name = $category->getName();
        $resource->parentId = $category->getParent()?->getId()->toRfc4122();
        $resource->productCount = $productCount;
        $resource->childCount = $childCount;

        return $resource;
    }
}
