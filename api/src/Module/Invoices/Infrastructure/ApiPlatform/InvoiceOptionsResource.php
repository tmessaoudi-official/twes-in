<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * What the invoice form offers, read with invoice.read alone: the company's currency and its scale, its establishments,
 * its active units and its active taxes of every kind, the defaults marked. Someone who writes invoices need not also
 * read the fiscal setup to fill one in.
 *
 * The customers and the products are NOT here: a company's book and catalogue are asked for a few at a time through
 * the two pickers beside this (docs/SPEC.md § 7, 2026-09-17, ruling 3), because twenty thousand products is a payload
 * nobody waits for. What is left is small, bounded and read whole because every bit of it is needed at once.
 */
#[ApiResource(
    shortName: 'InvoiceOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/invoice-options',
            provider: InvoiceOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class InvoiceOptionsResource
{
    public const string READ = 'invoice_options:read';
    private const array TEXT_OR_NULL = ['type' => ['string', 'null']];

    /** The company's currency, ISO 4217: prices and totals are in it. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    /** How many decimals the currency has: a total and a document discount are rounded to it. */
    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** @var list<array{id: string, code: string, name: string, isDefault: bool}> */
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

    /**
     * Line taxes (`percentage_line`, with a rate) and document taxes: fixed charges (`fixed_document`, with an amount)
     * and withholdings (`withholding_total`, with a rate and a threshold).
     *
     * @var list<array{id: string, code: string, name: string, kind: string, family: string, rate: string|null, amount: string|null, threshold: string|null, entersVatBase: bool, isDefault: bool}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'kind', 'family', 'rate', 'amount', 'threshold', 'entersVatBase', 'isDefault'],
            'properties' => [
                'id' => ['type' => 'string'],
                'code' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['percentage_line', 'fixed_document', 'withholding_total']],
                'family' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']],
                'rate' => self::TEXT_OR_NULL,
                'amount' => self::TEXT_OR_NULL,
                'threshold' => self::TEXT_OR_NULL,
                'entersVatBase' => ['type' => 'boolean'],
                'isDefault' => ['type' => 'boolean'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];
}
