<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the customer form offers, read with customer.read alone: the registration numbers the company's preset knows
 * (with their shape and whether a domestic business must carry them), the regimes it offers customers, and the
 * company's active taxes, labelled in the reader's language. Someone who may manage customers need not also read
 * the company's profile or its fiscal setup to fill the form.
 */
#[ApiResource(
    shortName: 'CustomerOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customer-options',
            provider: CustomerOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class CustomerOptionsResource
{
    public const string READ = 'customer_options:read';

    /** The company's country: an address without one is there. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $countryCode = '';

    /** @var list<CustomerIdentifierOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'label', 'pattern', 'requiredForBusiness'],
            'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string'], 'pattern' => ['type' => 'string', 'description' => 'A regular expression the whole value matches, without delimiters.'], 'requiredForBusiness' => ['type' => 'boolean']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $identifiers = [];

    /** @var list<CustomerRegimeOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'label', 'excludedFamilies'],
            'properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string'], 'excludedFamilies' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ],
    ])]
    #[Groups([self::READ])]
    public array $regimes = [];

    /** @var list<CustomerTaxOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'family'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'family' => ['type' => 'string']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];
}
