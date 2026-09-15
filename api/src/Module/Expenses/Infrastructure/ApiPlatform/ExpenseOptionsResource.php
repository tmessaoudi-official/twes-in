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
 * vendors with their terms and default category, its active categories, the rates on the net that may tax an expense,
 * and the ways an expense is paid.
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

    /** @var list<array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'number', 'name', 'paymentTermsDays', 'defaultExpenseCategoryId'],
            'properties' => [
                'id' => ['type' => 'string'],
                'number' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'paymentTermsDays' => ['type' => ['integer', 'null']],
                'defaultExpenseCategoryId' => ['type' => ['string', 'null']],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $vendors = [];

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
}
