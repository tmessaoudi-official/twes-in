<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * The lines of a note that deliver an accepted quote, checked against it: the goods leave stock with the note and are
 * billed through it, at the price and with the discount the customer accepted, so a line drawn from a quote keeps the
 * quote's product, unit, price and taxes, a note delivers one quote, of its customer and establishment, and never more
 * of a line than the quote's notes that are not cancelled leave of it.
 */
final readonly class QuoteLines
{
    public function __construct(private DeliverableQuotes $quotes, private DeliveryNoteRepository $notes)
    {
    }

    /**
     * The lines as the note will write them: a line naming a quote line takes from it what it leaves out, and what the
     * note already names is kept by whoever checks the lines next, so the quote's own product, unit and taxes, retired
     * since, still pass. `$current` is the draft being revised, whose own lines are not counted as taken.
     *
     * @param list<DeliveryNoteLineInput> $lines
     *
     * @return array{list<DeliveryNoteLineInput>, array{products: list<string>, units: list<string>, taxes: list<string>}}
     *
     * @throws InvalidDeliveryNote
     */
    public function resolved(Company $company, array $lines, Customer $customer, Establishment $establishment, ?DeliveryNote $current, bool $locked): array
    {
        $kept = ['products' => [], 'units' => [], 'taxes' => []];
        $named = [];
        foreach ($lines as $index => $line) {
            if (null === $line->sourceQuoteLineId) {
                continue;
            }
            $key = $line->sourceQuoteLineId->toRfc4122();
            if (isset($named[$key])) {
                throw new InvalidDeliveryNote("lines[$index].sourceQuoteLineId", 'A quote line is delivered once on a note: change the quantity of the line already there.', 'line_drawn_once');
            }
            $named[$key] = $line->sourceQuoteLineId;
        }
        if ([] === $named) {
            return [$lines, $kept];
        }

        $quotes = $this->quotes->ofLines($company, array_values($named), $locked);
        if (\count($quotes) > 1) {
            throw new InvalidDeliveryNote('lines', \sprintf('A delivery note delivers one quote, and these lines come from %d: draw one note from each.', \count($quotes)), 'one_source_document', ['count' => \count($quotes)]);
        }
        $quote = $quotes[0] ?? null;
        if (null !== $quote) {
            $refused = QuoteDelivery::refusal($quote);
            if (null !== $refused) {
                throw new InvalidDeliveryNote(...\array_slice($refused, 1));
            }
            if (!$quote->customer->standingFor()->getId()->equals($customer->standingFor()->getId())) {
                throw new InvalidDeliveryNote('customerId', \sprintf('A note delivering the quote %s goes to its customer, %s.', QuoteDelivery::reference($quote), $quote->customer->standingFor()->getProfile()->name), 'customer_of_source', ['reference' => QuoteDelivery::reference($quote), 'name' => $quote->customer->standingFor()->getProfile()->name]);
            }
            if (!$quote->establishment->getId()->equals($establishment->getId())) {
                throw new InvalidDeliveryNote('establishmentId', \sprintf('A note delivering the quote %s leaves its establishment, %s.', QuoteDelivery::reference($quote), $quote->establishment->getCode()), 'establishment_of_source', ['reference' => QuoteDelivery::reference($quote), 'code' => $quote->establishment->getCode()]);
            }
        }
        $taken = null === $quote ? [] : $this->notes->quoteLineQuantities($company->getId(), array_values($named), $current?->getId());

        $resolved = [];
        foreach ($lines as $index => $line) {
            $source = null === $line->sourceQuoteLineId ? null : (null === $quote ? null : QuoteDelivery::line($quote, $line->sourceQuoteLineId));
            if (null === $line->sourceQuoteLineId) {
                $resolved[] = $line;
                continue;
            }
            if (null === $source) {
                throw new InvalidDeliveryNote("lines[$index].sourceQuoteLineId", 'No quote of this company has this line.', 'unknown_choice');
            }
            try {
                $resolved[] = self::fromQuote($line, $source, Decimal::of($source->quantity)->sub(Decimal::of($taken[$source->id->toRfc4122()] ?? '0')));
            } catch (InvalidDeliveryNote $refused) {
                throw $refused->within("lines[$index]");
            }
            $product = $source->product?->standingFor();
            if (null !== $product) {
                $kept['products'][] = $product->getId()->toRfc4122();
            }
            $kept['units'][] = $source->unit->getId()->toRfc4122();
            foreach ($source->taxes as $tax) {
                $kept['taxes'][] = $tax->getId()->toRfc4122();
            }
        }

        return [$resolved, $kept];
    }

    /** @throws InvalidDeliveryNote */
    private static function fromQuote(DeliveryNoteLineInput $line, QuoteLineToDeliver $source, \BcMath\Number $left): DeliveryNoteLineInput
    {
        $product = $source->product?->standingFor();
        if (null !== $line->productId && !$line->productId->equals($product?->getId()) && !$line->productId->equals($source->product?->getId())) {
            throw new InvalidDeliveryNote('productId', 'A line delivered from a quote delivers the product the quote offered: add another line for anything else.', 'source_product');
        }
        if (null !== $line->unitId && !$line->unitId->equals($source->unit->getId())) {
            throw new InvalidDeliveryNote('unitId', \sprintf('A line delivered from a quote counts in the quote\'s unit, %s.', $source->unit->getCode()), 'source_unit', ['unit' => $source->unit->getCode()]);
        }
        if (null !== $line->unitPriceNet && (!is_numeric($line->unitPriceNet) || 0 !== Decimal::of($line->unitPriceNet)->compare(Decimal::of($source->unitPriceNet)))) {
            throw new InvalidDeliveryNote('unitPriceNet', \sprintf('A line delivered from a quote keeps the price the customer accepted, %s.', Decimal::format(Decimal::of($source->unitPriceNet), 4)), 'source_price', ['price' => Decimal::format(Decimal::of($source->unitPriceNet), 4)]);
        }
        $taxIds = array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(), $source->taxes);
        if (null !== $line->taxComponentIds) {
            $sent = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $line->taxComponentIds);
            if ([] !== array_diff($sent, $taxIds) || [] !== array_diff($taxIds, $sent)) {
                throw new InvalidDeliveryNote('taxComponentIds', 'A line delivered from a quote carries the taxes the quote charged.', 'source_taxes');
            }
        }
        if (is_numeric($line->quantity) && Decimal::of($line->quantity)->compare($left) > 0) {
            throw new InvalidDeliveryNote('quantity', \sprintf('Only %s of this quote line is left to deliver: its other notes take the rest.', Decimal::format($left, 3)), 'left_to_deliver', ['quantity' => Decimal::format($left, 3)]);
        }

        return new DeliveryNoteLineInput(
            $product?->getId(),
            '' === TypedText::trimmed($line->description ?? '') ? $source->description : $line->description,
            $line->quantity,
            $source->unit->getId(),
            $source->unitPriceNet,
            array_map(static fn (TaxComponent $tax): Uuid => $tax->getId(), $source->taxes),
            $line->lotCode,
            $source->id,
        );
    }
}
