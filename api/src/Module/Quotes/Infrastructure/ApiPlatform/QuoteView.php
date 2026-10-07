<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Domain\Quote;
use Psr\Clock\ClockInterface;

/**
 * How every quotes endpoint answers a quote: its figures worked out, whether it expired on the company's day, and how
 * many files it carries, read once for a whole page.
 */
final readonly class QuoteView
{
    public function __construct(private QuoteTotals $totals, private ManageQuotes $manage, private ClockInterface $clock, private QuoteInvoices $invoices)
    {
    }

    public function of(Quote $quote): QuoteResource
    {
        $counts = $this->manage->attachmentCounts($quote->getCompany(), [$quote]);
        $deposits = $this->invoices->depositsOf($quote->getCompany(), [$quote->getId()]);

        return QuoteResource::of($quote, $this->totals->of($quote), $this->today($quote), $counts[$quote->getId()->toRfc4122()] ?? 0, $deposits[$quote->getId()->toRfc4122()] ?? []);
    }

    /**
     * @param list<Quote> $quotes one company's
     *
     * @return list<QuoteResource>
     */
    public function page(array $quotes): array
    {
        if ([] === $quotes) {
            return [];
        }
        $counts = $this->manage->attachmentCounts($quotes[0]->getCompany(), $quotes);
        $deposits = $this->invoices->depositsOf($quotes[0]->getCompany(), array_map(static fn (Quote $quote) => $quote->getId(), $quotes));

        return array_map(fn (Quote $quote): QuoteResource => QuoteResource::of($quote, $this->totals->of($quote), $this->today($quote), $counts[$quote->getId()->toRfc4122()] ?? 0, $deposits[$quote->getId()->toRfc4122()] ?? []), $quotes);
    }

    private function today(Quote $quote): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($quote->getCompany()->getTimezone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
