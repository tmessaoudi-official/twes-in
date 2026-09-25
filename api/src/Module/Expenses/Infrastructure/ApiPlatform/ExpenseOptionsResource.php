<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the expense form offers, read with expense.read alone: the company's currency and its decimals, its active
 * categories, the rates on the net that may tax an expense, and the ways an expense is paid.
 *
 * The VENDORS are no longer here — they are asked for a few at a time through the picker beside this
 * (docs/SPEC.md § 7, 2026-09-17, ruling 3). A book of suppliers is not a dropdown, and an expense already carries
 * its vendor's name, so a form opens on one without reading the book.
 */
#[ApiResource(
    shortName: 'ExpenseOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/expense-options',
            provider: ExpenseOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class ExpenseOptionsResource
{
    public const string READ = 'expense_options:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** @var list<array{id: string, name: string, parentId: string|null}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'name', 'parentId'],
            'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'parentId' => ['type' => ['string', 'null']]],
        ],
    ])]
    #[Groups([self::READ])]
    public array $categories = [];

    /** @var list<array{id: string, code: string, name: string, rate: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name', 'rate'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'rate' => ['type' => 'string', 'description' => 'Percent, three decimals.']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];

    /** @var list<string> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']]])]
    #[Groups([self::READ])]
    public array $paymentMethods = [];

    /**
     * What a payment's withholding may be declared under on Tunisia's TEJ platform, with the administration's own
     * label; empty for a company outside the Tunisian preset.
     *
     * @var list<array{code: string, label: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'label'],
            'properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string', 'description' => 'As the administration publishes it, in French.']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $withholdingOperationCodes = [];
}
