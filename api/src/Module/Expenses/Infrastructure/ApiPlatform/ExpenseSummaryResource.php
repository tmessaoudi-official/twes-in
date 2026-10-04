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
use App\Module\Expenses\Application\ExpenseSummary;
use Symfony\Component\Serializer\Attribute\Groups;

/** What the home page shows of the company's expenses: recorded so far this month, and over the same days of last month. */
#[ApiResource(
    shortName: 'ExpenseSummary',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/expense-summary',
            provider: ExpenseSummaryProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class ExpenseSummaryResource
{
    public const string READ = 'expense_summary:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    #[Groups([self::READ])]
    public int $currencyScale = 3;

    /** The company's own day, which names the month the figures are for. */
    #[Groups([self::READ])]
    public string $today = '';

    /** Taxes included, drafts left out; a decimal string at the currency's scale. */
    #[Groups([self::READ])]
    public string $month = '0';

    #[Groups([self::READ])]
    public string $lastMonth = '0';

    public static function of(ExpenseSummary $summary): self
    {
        $resource = new self();
        $resource->currency = $summary->currency;
        $resource->currencyScale = $summary->currencyScale;
        $resource->today = $summary->today;
        $resource->month = $summary->month;
        $resource->lastMonth = $summary->lastMonth;

        return $resource;
    }
}
