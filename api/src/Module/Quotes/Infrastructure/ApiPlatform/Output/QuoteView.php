<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\Output;

use App\Module\Quotes\Application\Delivery\QuoteDeliveries;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNotes;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\Model\QuoteLine;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteResource;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Psr\Clock\ClockInterface;

/**
 * How every quotes endpoint answers a quote: its figures worked out, whether it expired on the company's day, how many
 * files it carries, and what its delivery notes deliver, read once for a whole page. The notes are told only to whoever
 * may open them: their numbers and states are the delivery notes'.
 */
final readonly class QuoteView
{
    private const string DELIVERY_NOTES = 'delivery_notes';
    private const string DELIVERY_NOTE_READ = 'delivery_note.read';

    public function __construct(
        private QuoteTotals $totals,
        private ManageQuotes $manage,
        private ClockInterface $clock,
        private QuoteInvoices $invoices,
        private QuoteDeliveryNotes $deliveries,
        private CompanyGuard $guard,
        private ModuleStates $modules,
    ) {
    }

    public function of(Quote $quote): QuoteResource
    {
        return $this->page([$quote])[0];
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
        $company = $quotes[0]->getCompany();
        $counts = $this->manage->attachmentCounts($company, $quotes);
        $deposits = $this->invoices->depositsOf($company, array_map(static fn (Quote $quote) => $quote->getId(), $quotes));
        $deliveries = $this->deliveriesOf($quotes);

        return array_map(fn (Quote $quote): QuoteResource => QuoteResource::of(
            $quote,
            $this->totals->of($quote),
            $this->today($quote),
            $counts[$quote->getId()->toRfc4122()] ?? 0,
            $deposits[$quote->getId()->toRfc4122()] ?? [],
            $deliveries[$quote->getId()->toRfc4122()] ?? null,
        ), $quotes);
    }

    /**
     * @param non-empty-list<Quote> $quotes
     *
     * @return array<string, QuoteDeliveries>
     */
    private function deliveriesOf(array $quotes): array
    {
        $company = $quotes[0]->getCompany();
        // Only an accepted quote is delivered, and it stays accepted.
        $delivered = array_filter($quotes, static fn (Quote $quote): bool => QuoteStatus::Accepted === $quote->getStatus());
        if ([] === $delivered || !$this->modules->isEnabled($company->getId(), self::DELIVERY_NOTES) || !$this->guard->may($company, self::DELIVERY_NOTE_READ)) {
            return [];
        }
        $lineIds = [];
        foreach ($delivered as $quote) {
            $lineIds[$quote->getId()->toRfc4122()] = array_map(static fn (QuoteLine $line) => $line->getId(), $quote->getLines());
        }

        return $this->deliveries->ofQuotes($company, $lineIds);
    }

    private function today(Quote $quote): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($quote->getCompany()->getTimezone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
