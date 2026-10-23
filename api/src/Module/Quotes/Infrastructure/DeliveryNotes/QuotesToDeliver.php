<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\DeliveryNotes;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Module\DeliveryNotes\Application\Quotes\DeliverableQuotes;
use App\Module\DeliveryNotes\Application\Quotes\QuoteLineToDeliver;
use App\Module\DeliveryNotes\Application\Quotes\QuoteToDeliver;
use App\Module\Quotes\Application\QuoteDeposit;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\Model\QuoteLine;
use App\Module\Quotes\Domain\Model\QuoteLineTax;
use App\Module\Quotes\Domain\Repository\QuoteRepository;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the delivery notes' `DeliverableQuotes` port from this module's quotes: each as the customer accepted it, and
 * whether it is invoiced as a whole, discounted on the whole or has a deposit invoice that stands, which the invoices'
 * own port says.
 */
final readonly class QuotesToDeliver implements DeliverableQuotes
{
    public function __construct(private QuoteRepository $quotes, private QuoteInvoices $invoices)
    {
    }

    public function ofId(Company $company, Uuid $quoteId, bool $locked): ?QuoteToDeliver
    {
        $quote = $locked ? $this->quotes->lockedOfIdInCompany($quoteId, $company->getId()) : $this->quotes->ofIdInCompany($quoteId, $company->getId());

        return null === $quote ? null : $this->toDeliver($company, $quote);
    }

    public function ofLines(Company $company, array $quoteLineIds, bool $locked): array
    {
        return array_map(fn (Quote $quote): QuoteToDeliver => $this->toDeliver($company, $quote), $this->quotes->ofLineIdsInCompany($quoteLineIds, $company->getId(), $locked));
    }

    private function toDeliver(Company $company, Quote $quote): QuoteToDeliver
    {
        $invoiceId = $quote->getInvoiceId();
        $deposits = $this->invoices->depositsOf($company, [$quote->getId()])[$quote->getId()->toRfc4122()] ?? [];
        $discount = $quote->getHeader()->discountAmount;

        return new QuoteToDeliver(
            $quote->getId(),
            $quote->getNumber(),
            QuoteStatus::Accepted === $quote->getStatus(),
            $quote->getCustomer(),
            $quote->getEstablishment(),
            $quote->getHeader()->customerReference,
            null !== $invoiceId && $this->invoices->stands($company, $invoiceId),
            null !== $discount && 0 !== Decimal::of($discount)->compare(0),
            [] !== array_filter($deposits, static fn (QuoteDeposit $deposit): bool => 'cancelled' !== $deposit->status),
            array_map(static fn (QuoteLine $line): QuoteLineToDeliver => new QuoteLineToDeliver(
                $line->getId(),
                $line->getPosition(),
                $line->getProduct(),
                $line->getDescription(),
                $line->getQuantity(),
                $line->getUnit(),
                $line->getUnitPriceNet(),
                array_map(static fn (QuoteLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes()),
                $line->getDiscountRate(),
                $line->getDiscountAmount(),
            ), $quote->getLines()),
        );
    }
}
