<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Fiscal\Domain\ValueObject\ChargeLeftOut;
use App\Fiscal\Domain\ValueObject\StampOnCorrection;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Domain\Model\InvoiceLineTax;
use App\Module\Invoices\Domain\ValueObject\InvoiceFigures;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What an invoice or a credit note comes to, worked out by the calculator every document shares: prices net of tax,
 * each line's discount, the document discount spread over the lines, the line taxes at the rates the document keeps,
 * then its fixed charges and its withholdings, rounded where the company's fiscal preset rounds VAT and at its
 * currency's scale. A credit note's figures are negative, and it gives back its share of its invoice's discounts and
 * withholdings, and its stamp as the preset says.
 */
final readonly class InvoiceTotals
{
    public function __construct(private FiscalPresets $presets, private CurrencyScales $scales)
    {
    }

    /**
     * The totals, or what refuses them named: a line's discount amount finer than the currency or above the line on
     * `lines[i].discountAmount`, a document discount finer than the currency or above the lines' net on
     * `discountAmount`, anything else the calculator refuses on `lines`.
     *
     * @throws InvalidInvoice
     */
    public function checked(Invoice $invoice): DocumentTotals
    {
        $this->checkLineDiscounts($invoice);
        $discount = $invoice->getHeader()->discountAmount;
        if (null !== $discount) {
            $scale = $this->scales->of($invoice->getCompany()->getCurrency());
            if (0 !== Decimal::round(Decimal::of($discount), $scale)->compare(Decimal::of($discount))) {
                throw new InvalidInvoice('discountAmount', \sprintf('The currency %s has %d decimals.', $invoice->getCompany()->getCurrency(), $scale));
            }
        }

        try {
            if (null !== $discount) {
                $lines = Decimal::absolute(Decimal::of($this->calculate($invoice, null)->subtotalNet));
                if (Decimal::of($discount)->compare($lines) > 0) {
                    throw new InvalidInvoice('discountAmount', \sprintf('A document discount is at most what the lines come to, %s.', Decimal::format($lines, 3)));
                }
            }

            return $this->of($invoice);
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            throw new InvalidInvoice($refused->field('quantity'), $refused->getMessage());
        }
    }

    /** @throws InvalidInvoice */
    private function checkLineDiscounts(Invoice $invoice): void
    {
        $scale = $this->scales->of($invoice->getCompany()->getCurrency());
        foreach ($invoice->getLines() as $i => $line) {
            $discount = $line->getDiscountAmount();
            if (null === $discount) {
                continue;
            }
            $field = \sprintf('lines[%d].discountAmount', $i);
            if (0 !== Decimal::round(Decimal::of($discount), $scale)->compare(Decimal::of($discount))) {
                throw new InvalidInvoice($field, \sprintf('The currency %s has %d decimals.', $invoice->getCompany()->getCurrency(), $scale));
            }
            $amount = Decimal::round(Decimal::of($line->getQuantity())->mul(Decimal::of($line->getUnitPriceNet()), Decimal::WORKING_SCALE), $scale);
            if (Decimal::of($discount)->compare($amount) > 0) {
                throw new InvalidInvoice($field, \sprintf('A line\'s discount is at most what the line comes to, %s.', Decimal::format($amount, $scale)));
            }
        }
    }

    /**
     * What a document prints: a draft's figures worked out now, an issued document's as issuing wrote them, never
     * recomputed (docs/SPEC.md § 7, 2026-09-14). Amounts at the currency's scale.
     *
     * @throws InvalidDocument           when a draft cannot be totalled
     * @throws UnsupportedTaxCombination when a draft's lines combine taxes the calculator does not
     */
    public function figures(Invoice $invoice): InvoiceFigures
    {
        $scale = $this->scales->of($invoice->getCompany()->getCurrency());

        return $invoice->getIssuedFigures()?->atScale($scale) ?? InvoiceFigures::of($this->of($invoice), $scale);
    }

    /**
     * The figures issuing fixes, checked as a draft's are.
     *
     * @throws InvalidInvoice
     */
    public function issued(Invoice $invoice): InvoiceFigures
    {
        return InvoiceFigures::of($this->checked($invoice), $this->scales->of($invoice->getCompany()->getCurrency()));
    }

    /**
     * @throws InvalidDocument           when the document cannot be totalled
     * @throws UnsupportedTaxCombination when the lines combine taxes the calculator does not
     */
    public function of(Invoice $invoice): DocumentTotals
    {
        $corrected = InvoiceType::CreditNote === $invoice->getType() ? $invoice->getCorrectedInvoice() : null;
        if (null === $corrected) {
            return $this->calculate($invoice, $invoice->getHeader()->discountAmount);
        }
        // What a credit note gives back of its invoice's discount and stamp follows from what its lines come to.
        $siblings = self::issuedSiblings($corrected, $invoice);
        $base = $this->calculate($invoice, null)->subtotalNet;
        $completes = self::completes($corrected, $siblings, $base);
        $discount = $this->documentDiscountGivenBack($corrected, $siblings, $base, $completes);
        $totals = $this->calculate($invoice, $discount, $completes);
        $left = $completes ? $this->taxesLeft($corrected, $siblings, $totals) : [];

        return [] === $left ? $totals : $this->calculate($invoice, $discount, $completes, $left);
    }

    /**
     * What is left of each percentage tax its invoice charged, for the credit note completing it: the invoice rounded
     * each tax once over its lines and each credit note rounds its own, so taken alone the credit notes can come to a
     * unit more or less than the invoice charged. Only where the difference is what that rounding can make, one unit a
     * credit note at most, so a note crediting other figures is never bent to the invoice's.
     *
     * @param list<Invoice> $siblings
     *
     * @return array<string, string>
     */
    private function taxesLeft(Invoice $corrected, array $siblings, DocumentTotals $totals): array
    {
        $scale = $this->scales->of($corrected->getCompany()->getCurrency());
        $left = [];
        foreach ($corrected->getIssuedFigures()->taxes ?? [] as $tax) {
            $left[$tax['code']] = ($left[$tax['code']] ?? Decimal::zero())->add(Decimal::absolute(Decimal::of($tax['amount'])));
        }
        foreach ($siblings as $sibling) {
            foreach ($sibling->getIssuedFigures()->taxes ?? [] as $tax) {
                if (isset($left[$tax['code']])) {
                    $left[$tax['code']] = $left[$tax['code']]->sub(Decimal::absolute(Decimal::of($tax['amount'])));
                }
            }
        }
        $reach = Decimal::of((string) (\count($siblings) + 1))->div((new Number(10))->pow($scale), $scale);
        $given = [];
        foreach ($totals->taxes as $tax) {
            $amount = $left[$tax->code] ?? null;
            $gap = null === $amount ? null : Decimal::absolute(Decimal::absolute(Decimal::of($tax->amount))->sub($amount));
            if (null !== $gap && 0 !== $gap->compare(0) && $gap->compare($reach) <= 0 && $amount->compare(0) >= 0) {
                $given[$tax->code] = Decimal::format($amount, $scale);
            }
        }

        return $given;
    }

    /**
     * What each line of a credit note gives back of the discount of the invoice line it names, when that line was
     * discounted by an amount: its share by quantity, never more than what the credit notes already issued left of it,
     * and all that is left once the line is credited in full (docs/fiscal/TN.md § 4a), so the credit notes of a line
     * add up to its discount exactly. Lines naming none, or a line discounted by a rate, keep what they say.
     *
     * @param list<array{0: Uuid|null, 1: string, 2: string}> $lines each line's credited line, quantity and unit price
     *
     * @return array<int, string> by the line's position
     */
    public function lineDiscountsGivenBack(Invoice $correction, array $lines): array
    {
        $corrected = $correction->getCorrectedInvoice();
        if (InvoiceType::CreditNote !== $correction->getType() || null === $corrected) {
            return [];
        }
        $scale = $this->scales->of($corrected->getCompany()->getCurrency());
        $credited = [];
        foreach ($corrected->getLines() as $line) {
            $credited[$line->getId()->toRfc4122()] = $line;
        }
        // What the credit notes already issued took of each line, then what this one takes, line after line.
        $taken = [];
        foreach (self::issuedSiblings($corrected, $correction) as $sibling) {
            foreach ($sibling->getLines() as $line) {
                $id = $line->getCreditedLineId()?->toRfc4122();
                if (null !== $id) {
                    $taken[$id] = [
                        ($taken[$id][0] ?? Decimal::zero())->add(Decimal::of($line->getQuantity())),
                        ($taken[$id][1] ?? Decimal::zero())->add(Decimal::of($line->getDiscountAmount() ?? '0')),
                    ];
                }
            }
        }

        $amounts = [];
        foreach ($lines as $position => [$creditedLineId, $quantity, $unitPrice]) {
            $line = $credited[$creditedLineId?->toRfc4122() ?? ''] ?? null;
            $discount = $line?->getDiscountAmount();
            if (null === $line || null === $discount) {
                continue;
            }
            $id = $line->getId()->toRfc4122();
            [$quantityTaken, $discountTaken] = $taken[$id] ?? [Decimal::zero(), Decimal::zero()];
            $left = Decimal::of($discount)->sub($discountTaken);
            $left = $left->compare(0) > 0 ? $left : Decimal::zero();
            $whole = Decimal::of($line->getQuantity());
            $share = $quantityTaken->add(Decimal::of($quantity))->compare($whole) >= 0
                ? $left
                : self::least(Decimal::round(Decimal::of($discount)->mul(Decimal::of($quantity), Decimal::WORKING_SCALE)->div($whole, Decimal::WORKING_SCALE), $scale), $left);
            $share = self::least($share, Decimal::round(Decimal::of($quantity)->mul(Decimal::of($unitPrice), Decimal::WORKING_SCALE), $scale));
            $amounts[$position] = Decimal::format($share, 3);
            $taken[$id] = [$quantityTaken->add(Decimal::of($quantity)), $discountTaken->add($share)];
        }

        return $amounts;
    }

    /**
     * Writes on a draft credit note what its lines give back of their invoice lines' discounts, as they stand now: a
     * credit note issued since it was last saved may have taken some of them.
     */
    public function settleLineDiscounts(Invoice $correction): void
    {
        $lines = $correction->getLines();
        $amounts = $this->lineDiscountsGivenBack($correction, array_map(static fn (InvoiceLine $line): array => [$line->getCreditedLineId(), $line->getQuantity(), $line->getUnitPriceNet()], $lines));
        if ([] === $amounts) {
            return;
        }
        $byId = [];
        foreach ($amounts as $position => $amount) {
            $byId[$lines[$position]->getId()->toRfc4122()] = $amount;
        }
        $correction->giveBackLineDiscounts($byId);
    }

    /**
     * The credit notes of an invoice already issued, but the one being worked out: a draft has taken nothing.
     *
     * @return list<Invoice>
     */
    private static function issuedSiblings(Invoice $corrected, Invoice $correction): array
    {
        return array_values(array_filter(
            $corrected->getCorrections(),
            static fn (Invoice $sibling): bool => !$sibling->getId()->equals($correction->getId()) && null !== $sibling->getIssuedFigures(),
        ));
    }

    /**
     * Whether a credit note whose lines come to `$base` completes its invoice: it credits at least what the lines of the
     * invoice came to, less what its credit notes already issued credited.
     *
     * @param list<Invoice> $siblings
     */
    private static function completes(Invoice $corrected, array $siblings, string $base): bool
    {
        $left = Decimal::absolute(Decimal::of($corrected->getIssuedFigures()->subtotalNet ?? '0'));
        foreach ($siblings as $sibling) {
            $left = $left->sub(Decimal::absolute(Decimal::of($sibling->getIssuedFigures()->subtotalNet ?? '0')));
        }

        return Decimal::absolute(Decimal::of($base))->compare($left) >= 0;
    }

    /**
     * What a credit note gives back of its invoice's document discount, spread over the lines by their base as the
     * calculator spreads it: its share by its base, never more than what the credit notes already issued left, and
     * all that is left from the credit note completing the invoice (docs/fiscal/TN.md § 4a). Null when there is none.
     *
     * @param list<Invoice> $siblings
     */
    private function documentDiscountGivenBack(Invoice $corrected, array $siblings, string $base, bool $completes): ?string
    {
        $figures = $corrected->getIssuedFigures();
        $discount = Decimal::absolute(Decimal::of($figures->documentDiscount ?? '0'));
        if ($discount->compare(0) <= 0) {
            return null;
        }
        $left = $discount;
        foreach ($siblings as $sibling) {
            $left = $left->sub(Decimal::absolute(Decimal::of($sibling->getIssuedFigures()->documentDiscount ?? '0')));
        }
        $base = Decimal::absolute(Decimal::of($base));
        $invoiceBase = Decimal::absolute(Decimal::of($figures->subtotalNet ?? '0'));
        if ($left->compare(0) <= 0 || $invoiceBase->compare(0) <= 0) {
            return null;
        }
        $scale = $this->scales->of($corrected->getCompany()->getCurrency());
        $share = $completes ? $left : self::least(Decimal::round($discount->mul($base, Decimal::WORKING_SCALE)->div($invoiceBase, Decimal::WORKING_SCALE), $scale), $left);
        $share = self::least($share, $base);

        return $share->compare(0) > 0 ? Decimal::format($share, $scale) : null;
    }

    private static function least(Number $one, Number $other): Number
    {
        return $one->compare($other) <= 0 ? $one : $other;
    }

    /**
     * What a credit note gives back of the stamp its invoice charged, by code: per the company's fiscal preset, once,
     * all of it from the credit note completing the invoice (docs/fiscal/TN.md § 4a), or never.
     *
     * @return array<string, string>
     */
    private function stampsGivenBack(Invoice $corrected, Invoice $correction, bool $completes): array
    {
        if (!$completes || StampOnCorrection::GivenBackOnce !== $this->presets->get($corrected->getCompany()->getFiscalPreset())->stampOnCorrection) {
            return [];
        }

        return self::stampsLeft($corrected, self::issuedSiblings($corrected, $correction));
    }

    /**
     * Why a credit note's figures leave out each fixed charge per document chosen on it, by code: the screen says what
     * holds rather than guessing it from a charge missing. None on an invoice, which charges what it is given.
     *
     * @return array<string, ChargeLeftOut>
     */
    public function chargesLeftOut(Invoice $invoice): array
    {
        $corrected = InvoiceType::CreditNote === $invoice->getType() ? $invoice->getCorrectedInvoice() : null;
        if (null === $corrected) {
            return [];
        }
        $siblings = self::issuedSiblings($corrected, $invoice);
        $completes = self::completes($corrected, $siblings, $this->calculate($invoice, null)->subtotalNet);
        $kept = StampOnCorrection::Kept === $this->presets->get($corrected->getCompany()->getFiscalPreset())->stampOnCorrection;
        $left = self::stampsLeft($corrected, $siblings);
        $out = [];
        foreach ($invoice->getDocumentTaxes() as $tax) {
            if (TaxKind::FixedDocument !== $tax->getKind()) {
                continue;
            }
            $why = match (true) {
                $kept => ChargeLeftOut::Kept,
                !isset($left[$tax->getCode()]) => ChargeLeftOut::NotCharged,
                !$completes => ChargeLeftOut::GivenBackByCompletingNote,
                default => null,
            };
            if (null !== $why) {
                $out[$tax->getCode()] = $why;
            }
        }

        return $out;
    }

    /**
     * What is left to give back of each stamp the invoice charged, by code, once its credit notes already issued gave
     * theirs: none for a code it never charged.
     *
     * @param list<Invoice> $siblings
     *
     * @return array<string, string>
     */
    private static function stampsLeft(Invoice $corrected, array $siblings): array
    {
        $left = [];
        foreach ($corrected->getIssuedFigures()->fixedTaxes ?? [] as $charge) {
            $left[$charge['code']] = ($left[$charge['code']] ?? Decimal::zero())->add(Decimal::absolute(Decimal::of($charge['amount'])));
        }
        foreach ($siblings as $sibling) {
            foreach ($sibling->getIssuedFigures()->fixedTaxes ?? [] as $charge) {
                if (isset($left[$charge['code']])) {
                    $left[$charge['code']] = $left[$charge['code']]->sub(Decimal::absolute(Decimal::of($charge['amount'])));
                }
            }
        }

        return array_map(static fn (Number $amount): string => Decimal::format($amount, 3), array_filter($left, static fn (Number $amount): bool => $amount->compare(0) > 0));
    }

    /** @param array<string, string> $taxesLeft */
    private function calculate(Invoice $invoice, ?string $documentDiscount, bool $completes = false, array $taxesLeft = []): DocumentTotals
    {
        $company = $invoice->getCompany();
        $lines = array_map(static fn (InvoiceLine $line): LineInput => new LineInput(
            $line->getQuantity(),
            $line->getUnitPriceNet(),
            $line->getDiscountRate(),
            array_map(static fn (InvoiceLineTax $tax): TaxInput => TaxInput::percentage($tax->getCode(), Rate::fromPercentage($tax->getRate()), $tax->entersVatBase()), $line->getTaxes()),
            null !== $line->getDeduction(),
            self::deducted($line),
            $line->getDiscountAmount(),
        ), $invoice->getLines());
        $corrected = InvoiceType::CreditNote === $invoice->getType() ? $invoice->getCorrectedInvoice() : null;
        $remaining = null === $corrected ? null : $this->remainingWithholdings($corrected, $invoice);
        $stamps = null === $corrected ? null : $this->stampsGivenBack($corrected, $invoice, $completes);
        $documentTaxes = [];
        foreach ($invoice->getDocumentTaxes() as $tax) {
            if (TaxKind::FixedDocument === $tax->getKind()) {
                if (null === $stamps) {
                    $documentTaxes[] = TaxInput::fixed($tax->getCode(), $tax->getAmount() ?? '0');
                } elseif (isset($stamps[$tax->getCode()])) {
                    // A stamp is charged once, on the invoice: a credit note gives back only what it charged, once.
                    $documentTaxes[] = TaxInput::fixed($tax->getCode(), $stamps[$tax->getCode()]);
                }
                continue;
            }
            $rate = Rate::fromPercentage($tax->getRate() ?? '0');
            if (null === $remaining) {
                $documentTaxes[] = TaxInput::withholding($tax->getCode(), $rate, $tax->getThreshold() ?? '0');
                continue;
            }
            // A credit note withholds what its invoice withheld, whatever its own total: its own threshold never decides.
            if (isset($remaining[$tax->getCode()])) {
                [$base, $amount] = $remaining[$tax->getCode()];
                $documentTaxes[] = TaxInput::withholdingCompleting($tax->getCode(), $rate, $base, $amount);
            }
        }

        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            InvoiceType::CreditNote === $invoice->getType(),
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            $lines,
            $documentDiscount,
            $documentTaxes,
            $taxesLeft,
        ));
    }

    /**
     * What a line giving a deposit back subtracts of each of its taxes, by the code the line charges it under.
     *
     * @return array<string, string>
     */
    private static function deducted(InvoiceLine $line): array
    {
        $amounts = [];
        foreach ($line->getTaxes() as $tax) {
            $amount = $tax->getDeductedAmount();
            if (null !== $amount) {
                $amounts[$tax->getCode()] = $amount;
            }
        }

        return $amounts;
    }

    /**
     * What is left of each withholding the corrected invoice charged, by code, as base and amount: the invoice's own,
     * less what its other issued credit notes have already taken. The correction completing a base takes what is left
     * of its amount, so the credit notes of a withheld invoice add up to it exactly (docs/SPEC.md § 8, row 25) rather
     * than to what each of them rounded. A base already taken in full leaves nothing, and a correction beyond it
     * rounds its own share as any document does.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function remainingWithholdings(Invoice $corrected, Invoice $correction): array
    {
        $remaining = [];
        foreach ($corrected->getIssuedFigures()->withholdings ?? [] as $held) {
            $remaining[$held['code']] = [Decimal::absolute(Decimal::of($held['base'])), Decimal::absolute(Decimal::of($held['amount']))];
        }
        foreach ($corrected->getCorrections() as $sibling) {
            // This correction's own figures are what is being worked out here; a draft sibling has none, and took nothing.
            if ($sibling->getId()->equals($correction->getId())) {
                continue;
            }
            foreach ($sibling->getIssuedFigures()->withholdings ?? [] as $held) {
                if (isset($remaining[$held['code']])) {
                    $remaining[$held['code']] = [
                        $remaining[$held['code']][0]->sub(Decimal::absolute(Decimal::of($held['base']))),
                        $remaining[$held['code']][1]->sub(Decimal::absolute(Decimal::of($held['amount']))),
                    ];
                }
            }
        }

        $scale = $this->scales->of($corrected->getCompany()->getCurrency());
        $left = [];
        foreach ($remaining as $code => [$base, $amount]) {
            $spent = $base->compare(0) <= 0 || $amount->compare(0) < 0;
            $left[$code] = [Decimal::format($spent ? Decimal::zero() : $base, $scale), Decimal::format($spent ? Decimal::zero() : $amount, $scale)];
        }

        return $left;
    }
}
