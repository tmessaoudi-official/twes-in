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
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What each status chip of « Factures » would list (docs/SPEC.md § 7, 2026-09-26): under the same words, kind and
 * customer as the list, the status left aside. `overdue` is the list's own rule on the company's day, and an overdue
 * document is counted in its status too, as the list shows it under both.
 */
#[ApiResource(
    shortName: 'InvoiceStatusCounts',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/invoice-status-counts',
            provider: InvoiceStatusCountsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'The list\'s own words.'),
                'documentType' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['invoice', 'credit_note']]),
                'customerId' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid']),
            ],
        ),
    ],
)]
final class InvoiceStatusCountsResource
{
    public const string READ = 'invoice_status_counts:read';
    private const array COUNT = ['type' => 'integer', 'minimum' => 0];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $all = 0;

    /** @var array<string, int> */
    #[ApiProperty(schema: [
        'type' => 'object',
        'required' => ['draft', 'issued', 'overdue', 'partially_paid', 'paid', 'cancelled'],
        'properties' => [
            'draft' => self::COUNT,
            'issued' => self::COUNT,
            'overdue' => self::COUNT,
            'partially_paid' => self::COUNT,
            'paid' => self::COUNT,
            'cancelled' => self::COUNT,
        ],
    ])]
    #[Groups([self::READ])]
    public array $statuses = [];

    /** @param array{all: int, statuses: array<string, int>} $counts */
    public static function of(array $counts): self
    {
        $resource = new self();
        $resource->all = $counts['all'];
        $resource->statuses = $counts['statuses'];

        return $resource;
    }
}
