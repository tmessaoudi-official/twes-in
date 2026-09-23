<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's products (docs/SPEC.md § 4 product). Read with product.read, added and revised with product.write;
 * never deleted, deactivated instead. The shape is checked here, the company's units, categories and taxes by the
 * use case. Prices are decimal strings answered at four decimals.
 */
#[ApiResource(
    shortName: 'Product',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: ProductCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the reference or name, or one of its codes spelled whole, whatever their case and accents; under three characters, the exact reference only.'),
                'kind' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['goods', 'service']]),
                'isActive' => new QueryParameter(schema: ['type' => 'boolean'], castToNativeType: true),
                'order[reference]' => new QueryParameter(schema: self::DIRECTION),
                'order[name]' => new QueryParameter(schema: self::DIRECTION),
                'order[kind]' => new QueryParameter(schema: self::DIRECTION),
                'order[category]' => new QueryParameter(schema: self::DIRECTION, description: 'By the name of the product\'s own category; products without one come last.'),
                'order[isActive]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/products/{productId}',
            provider: ProductItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/products',
            processor: CreateProductProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/products/{productId}',
            processor: ReviseProductProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class ProductResource
{
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    public const string READ = 'product:read';
    public const string WRITE = 'product:write';
    /** Nulls are answered: an absent description and a product without a category read alike. */
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];
    private const string PRICE_SCHEMA_PATTERN = '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** Letters, digits and . _ / -, up to 32 characters, unique in the company. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $reference = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: ProductDetails::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: ProductDetails::DESCRIPTION_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $description = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['goods', 'service']])]
    #[Assert\Choice(choices: ['goods', 'service'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $kind = 'goods';

    /**
     * How its stock is told apart (docs/SPEC.md § 7, 2026-09-23 02:40): chosen before its first stock movement, `none`
     * for a service. Left out of a write, it is kept.
     */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['none', 'lot', 'serial']])]
    #[Assert\Choice(choices: ['none', 'lot', 'serial'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $tracking = null;

    /** One of the company's units (GET .../units); a retired one stays with the products that have it. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $unitId = '';

    /** The net price of one unit, a decimal string with at most four decimals. */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => self::PRICE_SCHEMA_PATTERN, 'example' => '1250.5000'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $unitPriceNet = '';

    /**
     * What one unit costs the company, a decimal string with at most four decimals; never printed. Null, and ignored
     * on a write, for a caller without product.cost.read.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'pattern' => self::PRICE_SCHEMA_PATTERN])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $costPrice = null;

    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $categoryId = null;

    /**
     * The codes it answers to (docs/SPEC.md § 7, 2026-09-22 11:05), written on their own: PUT .../barcodes. A
     * supplier's codes are left out for a caller without product.cost.read.
     *
     * @var list<ProductBarcodeRow>
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public array $barcodes = [];

    /** @var list<string> the ids of the company's line taxes a new line for this product starts with */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Uuid()], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $defaultTaxComponentIds = [];

    /** @var array<string, string|int|float|bool> values by the company's custom field keys (GET .../custom-fields?entity=product) */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['oneOf' => [['type' => 'string'], ['type' => 'number'], ['type' => 'boolean']]]])]
    #[Groups([self::READ, self::WRITE])]
    public array $customFields = [];

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    public static function of(Product $product, bool $withCosts): self
    {
        $details = $product->getDetails();
        $resource = new self();
        $resource->id = $product->getId()->toRfc4122();
        $resource->reference = $product->getReference();
        $resource->name = $details->name;
        $resource->description = $details->description;
        $resource->kind = $details->kind->value;
        $resource->tracking = $product->getTracking()->value;
        $resource->unitId = $product->getUnit()->getId()->toRfc4122();
        $resource->unitPriceNet = $details->unitPriceNet;
        $resource->costPrice = $withCosts ? $details->costPrice : null;
        $resource->categoryId = $product->getCategory()?->getId()->toRfc4122();
        $resource->barcodes = ProductBarcodeRow::listOf($product, $withCosts);
        $resource->defaultTaxComponentIds = $product->getDefaultTaxComponentIds();
        $resource->customFields = $product->getCustomFields();
        $resource->isActive = $product->isActive();

        return $resource;
    }

    /** @throws InvalidProduct */
    public function input(bool $seesCosts): ProductInput
    {
        return new ProductInput(
            $this->reference,
            new ProductDetails($this->name, $this->description, ProductKind::from($this->kind), $this->unitPriceNet, $seesCosts ? $this->costPrice : null),
            Uuid::fromString($this->unitId),
            null === $this->categoryId ? null : Uuid::fromString($this->categoryId),
            array_map(static fn (string $id): Uuid => Uuid::fromString($id), $this->defaultTaxComponentIds),
            $this->isActive,
            $this->customFields,
            tracking: null === $this->tracking ? null : ProductTracking::from($this->tracking),
            seesCosts: $seesCosts,
        );
    }
}
