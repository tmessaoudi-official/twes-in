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
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the product form offers, read with product.read alone: the company's currency and its scale, its active units
 * and its active taxes charged on a line. Someone who may manage products need not also read the company's fiscal
 * setup to fill the form.
 */
#[ApiResource(
    shortName: 'ProductOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/product-options',
            provider: ProductOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class ProductOptionsResource
{
    public const string READ = 'product_options:read';

    /** The company's currency, ISO 4217: prices are in it. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    /** How many decimals the currency has: an amount is rounded to it, a unit price may carry four. */
    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** @var list<ProductUnitOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'decimals'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'decimals' => ['type' => 'integer']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $units = [];

    /** @var list<ProductTaxOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'family'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'family' => ['type' => 'string', 'enum' => ['vat', 'levy']]],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];
}
