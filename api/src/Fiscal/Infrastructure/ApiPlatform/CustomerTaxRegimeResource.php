<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The tax regimes a company's customers may be under, read-only: they are the fiscal preset's, written by the seed.
 * The label comes translated into the caller's language.
 */
#[ApiResource(
    shortName: 'CustomerTaxRegime',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/customer-tax-regimes',
            provider: CustomerTaxRegimeCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class CustomerTaxRegimeResource
{
    public const string READ = 'customer_tax_regime:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $code = '';

    #[Groups([self::READ])]
    public string $label = '';

    /** @var list<string> the tax families a customer under this regime is not charged */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']]])]
    #[Groups([self::READ])]
    public array $excludedFamilies = [];

    /** Whether an invoice to such a customer prints a legal mention. */
    #[Groups([self::READ])]
    public bool $hasMention = false;

    #[Groups([self::READ])]
    public int $sortOrder = 0;
}
