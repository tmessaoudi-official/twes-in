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
use App\Module\Invoices\Application\InvoiceSummary;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * The home page's figures of the company's invoices, read with invoice.read, every one worked out by the API on the
 * company's day: what is still to collect and how late, the invoices to chase, the payments of the last six months
 * and the VAT invoiced this month.
 */
#[ApiResource(
    shortName: 'InvoiceSummary',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/invoice-summary',
            provider: InvoiceSummaryProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class InvoiceSummaryResource
{
    public const string READ = 'invoice_summary:read';
    private const array TEXT = ['type' => 'string'];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** The company's day the figures were worked out on, YYYY-MM-DD. */
    #[Groups([self::READ])]
    public string $today = '';

    /** What the issued and partly paid invoices still have due. */
    #[Groups([self::READ])]
    public string $outstanding = '0';

    #[Groups([self::READ])]
    public string $notYetDue = '0';

    /** What is due from invoices whose due day is before today. */
    #[Groups([self::READ])]
    public string $overdue = '0';

    #[Groups([self::READ])]
    public int $overdueCount = 0;

    /** How many days late the latest invoice is; null when none is. */
    #[ApiProperty(schema: ['type' => ['integer', 'null']])]
    #[Groups([self::READ])]
    public ?int $oldestOverdueDays = null;

    /** @var list<array{bucket: string, amount: string, count: int}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['bucket', 'amount', 'count'],
            'properties' => [
                'bucket' => ['type' => 'string', 'enum' => ['not_due', 'days_1_15', 'days_16_30', 'days_31_45', 'days_over_45']],
                'amount' => self::TEXT,
                'count' => ['type' => 'integer'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $aging = [];

    /**
     * The first four invoices to chase: the late ones, the latest first, then those due within a week. `daysLate` is
     * negative for a due day still ahead.
     *
     * @var list<array{invoiceId: string, number: string, customerName: string, dueDate: string, amountDue: string, daysLate: int}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['invoiceId', 'number', 'customerName', 'dueDate', 'amountDue', 'daysLate'],
            'properties' => [
                'invoiceId' => self::TEXT,
                'number' => self::TEXT,
                'customerName' => self::TEXT,
                'dueDate' => self::TEXT,
                'amountDue' => self::TEXT,
                'daysLate' => ['type' => 'integer'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $toChase = [];

    /** How many invoices there are to chase, and what they have due, beyond the four shown. */
    #[Groups([self::READ])]
    public int $toChaseCount = 0;

    #[Groups([self::READ])]
    public string $toChaseAmount = '0';

    /** @var list<array{month: string, amount: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'required' => ['month', 'amount'], 'properties' => ['month' => self::TEXT, 'amount' => self::TEXT]],
    ])]
    #[Groups([self::READ])]
    public array $collected = [];

    /** @var list<array{code: string, rate: string, amount: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'required' => ['code', 'rate', 'amount'], 'properties' => ['code' => self::TEXT, 'rate' => self::TEXT, 'amount' => self::TEXT]],
    ])]
    #[Groups([self::READ])]
    public array $vat = [];

    #[Groups([self::READ])]
    public string $vatTotal = '0';

    public static function of(InvoiceSummary $summary): self
    {
        $resource = new self();
        $resource->currency = $summary->currency;
        $resource->currencyScale = $summary->currencyScale;
        $resource->today = $summary->today;
        $resource->outstanding = $summary->outstanding;
        $resource->notYetDue = $summary->notYetDue;
        $resource->overdue = $summary->overdue;
        $resource->overdueCount = $summary->overdueCount;
        $resource->oldestOverdueDays = $summary->oldestOverdueDays;
        $resource->aging = $summary->aging;
        $resource->toChase = $summary->toChase;
        $resource->toChaseCount = $summary->toChaseCount;
        $resource->toChaseAmount = $summary->toChaseAmount;
        $resource->collected = $summary->collected;
        $resource->vat = $summary->vat;
        $resource->vatTotal = $summary->vatTotal;

        return $resource;
    }
}
