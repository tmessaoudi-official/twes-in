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
 * its active customers with the tax families their regime leaves out, their default discount and their default taxes,
 * its active products with what a line starts from, its active units and its active taxes of every kind, the defaults
 * marked. Someone who writes invoices need not also read customers, products or the fiscal setup to fill one.
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

    /** @var list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'number', 'name', 'excludedFamilies', 'defaultDiscountRate', 'defaultTaxComponentIds'],
            'properties' => [
                'id' => ['type' => 'string'],
                'number' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'excludedFamilies' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']]],
                'defaultDiscountRate' => self::TEXT_OR_NULL,
                'defaultTaxComponentIds' => ['type' => 'array', 'items' => ['type' => 'string']],
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
