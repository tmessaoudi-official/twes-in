<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the delivery note form offers, read with delivery_note.read alone: the company's currency and its scale, its
 * establishments, its active customers with the tax families their regime leaves out, its active products with what a
 * line starts from, its active units and its active line taxes. Someone who writes delivery notes need not also read
 * customers, products or the fiscal setup to fill one.
 */
#[ApiResource(
    shortName: 'DeliveryNoteOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/delivery-note-options',
            provider: DeliveryNoteOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class DeliveryNoteOptionsResource
{
    public const string READ = 'delivery_note_options:read';

    /** The company's currency, ISO 4217: prices and totals are in it. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    /** How many decimals the currency has: a total is rounded to it, a unit price may carry four. */
    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** @var list<array{id: string, code: string, name: string, isDefault: bool}> the default first */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'isDefault'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'isDefault' => ['type' => 'boolean']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $establishments = [];

    /** @var list<array{id: string, number: string, name: string, excludedFamilies: list<string>}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'number', 'name', 'excludedFamilies'],
            'properties' => [
                'id' => ['type' => 'string'],
                'number' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'excludedFamilies' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']]],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $customers = [];

    /** @var list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'reference', 'name', 'unitId', 'unitPriceNet', 'defaultTaxComponentIds'],
            'properties' => [
                'id' => ['type' => 'string'],
                'reference' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'unitId' => ['type' => 'string'],
                'unitPriceNet' => ['type' => 'string'],
                'defaultTaxComponentIds' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $products = [];

    /** @var list<array{id: string, code: string, name: string, decimals: int}> */
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

    /** @var list<array{id: string, code: string, name: string, family: string, rate: string, entersVatBase: bool}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'family', 'rate', 'entersVatBase'],
            'properties' => [
                'id' => ['type' => 'string'],
                'code' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'family' => ['type' => 'string', 'enum' => ['vat', 'levy']],
                'rate' => ['type' => 'string'],
                'entersVatBase' => ['type' => 'boolean'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];
}
