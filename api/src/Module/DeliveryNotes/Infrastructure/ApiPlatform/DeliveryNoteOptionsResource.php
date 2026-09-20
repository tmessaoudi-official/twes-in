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
 * establishments, its active units and its active line taxes. Someone who writes delivery notes need not also read
 * the fiscal setup to fill one in.
 *
 * The customers and the products are NOT here: they are asked for a few at a time through the two pickers beside this
 * (docs/SPEC.md § 7, 2026-09-17, ruling 3). What is left is small, bounded and read whole because all of it is needed
 * at once.
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
