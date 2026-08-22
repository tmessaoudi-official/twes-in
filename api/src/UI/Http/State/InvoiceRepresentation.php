<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use Twes\Domain\Document\DocumentTotals;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Shared\RoundingMode;
use Twes\UI\Http\ApiResource\FixedChargeResource;
use Twes\UI\Http\ApiResource\InvoiceLineResource;
use Twes\UI\Http\ApiResource\InvoiceResource;
use Twes\UI\Http\ApiResource\InvoiceTotalsResource;

/**
 * Translates a persisted `Invoice` into its HTTP representation. **One copy, three callers.**
 *
 * The transport-boundary twin of the Doctrine mapper: the aggregate cannot carry an `#[ApiResource]` attribute
 * because § Architecture forbids a framework dependency in `Domain/`, so something has to translate. Dependencies
 * point inward — this knows `Domain/`, and `Domain/` knows nothing about this.
 *
 * **EXTRACTED FROM {@see InvoiceProvider} when the write path landed, and the reason is not tidiness.** `GET`,
 * `POST /invoices` and `POST /invoices/{id}/issue` all answer with the same resource, so a private method on the
 * provider would have become two or three copies of the VAT allocation read-out — and the figures it assembles are
 * the ones that must not differ between a create response and a subsequent fetch of the same document. Two copies of
 * that is how one endpoint starts recomputing `net × rate` while another reads the allocated share.
 *
 * **THE ROUNDING MODE IS NOT A CHOICE MADE HERE.** `docs/spec/pricing-vectors.json` states it for every consumer:
 * *"half_up on every amount and every rate. There is NO per-case override."* Those vectors are the cross-tier
 * contract that stops Angular, Flutter and the server each inventing a rule, so this applies the same mode the
 * vectors do and passing anything else would put the server outside its own contract.
 *
 * **THE ROUNDING POINT, by contrast, COMES FROM THE DOCUMENT.** `PerRateGroup` versus `PerLine` is persisted per
 * document precisely so a company changing its setting cannot restate a document a client already holds — so it is
 * read from `DocumentIdentity` and never from configuration.
 *
 * Static, and therefore not a service: it has no state and no collaborators, and injecting it would suggest it could
 * be substituted. `RowHydrator` is the same shape on the persistence side, for the same reason.
 */
final readonly class InvoiceRepresentation
{
    /**
     * The mode the cross-tier vectors mandate. A constant rather than a parameter: a configurable rounding mode
     * would be a way to serve numbers that disagree with the vectors every client validates against.
     */
    private const RoundingMode MODE = RoundingMode::HalfUp;

    /**
     * @throws \Twes\Domain\Money\Exception\InvalidMoneyAmount if a figure does not fit the money column — which
     *                                                         cannot happen for a document the aggregate accepted,
     *                                                         because `Invoice` refuses a line set it cannot total
     */
    public static function of(PersistedInvoice $persisted): InvoiceResource
    {
        $invoice = $persisted->invoice;
        $totals = $invoice->totals($persisted->identity->vatRoundingPoint, self::MODE);
        $number = $invoice->number();

        $lineNets = $totals->lineNets();
        $vatByLine = $totals->vatByLine();

        $lines = [];

        // NO `assert($line instanceof DocumentLine)`. `Invoice::lines()` is already typed as a list of them, so
        // PHPStan reports the assertion as always-true — the same half-hollow-assertion class `CLAUDE.md` records
        // its PHPStan configuration catching in the SET-ROLE escalation proof. A dead assertion reads as a check.
        foreach ($invoice->lines() as $position => $line) {
            $lines[] = new InvoiceLineResource(
                position: $position,
                quantity: $line->quantity(),
                unitNet: $line->unitNet()->amount(),
                vatRate: $line->vatRate()->percentage(),
                // BY POSITION, from the totals rather than recomputed. `vatByLine()` is the ALLOCATED share of the
                // rate group's VAT — largest remainder, ties to the earliest line — so recomputing `net × rate`
                // here would produce figures that do not sum to `vatByRate`, which is the defect the allocation
                // rule exists to prevent.
                net: $lineNets[$position]->amount(),
                vat: $vatByLine[$position]->amount(),
            );
        }

        $charges = [];

        foreach ($invoice->fixedCharges() as $position => $charge) {
            $charges[] = new FixedChargeResource(
                position: $position,
                label: $charge->label(),
                amount: $charge->amount()->amount(),
            );
        }

        return new InvoiceResource(
            id: $persisted->identity->id,
            state: $invoice->state()->value,
            currency: $invoice->currency()->code(),
            clientId: $invoice->clientId(),
            number: $number?->number(),
            sequence: $number?->sequence(),
            lines: $lines,
            fixedCharges: $charges,
            totals: new InvoiceTotalsResource(
                subtotalNet: $totals->subtotalNet()->amount(),
                vatByRate: self::amountsByRate($totals),
                vatTotal: $totals->vatTotal()->amount(),
                fixedChargesTotal: $totals->fixedChargesTotal()->amount(),
                total: $totals->total()->amount(),
                vatRoundingPoint: $persisted->identity->vatRoundingPoint->value,
            ),
        );
    }

    /**
     * VAT per rate group as decimal strings, keyed by the rate at its CANONICAL scale.
     *
     * **THE KEYS ARE `"19.0000000000"`, NOT `"19"` — and this docblock claimed the latter.** `Rate::percentage()`
     * rescales to `PERCENTAGE_SCALE`, which is 10, so nineteen per cent is `19.0000000000` on the wire. [Verified:
     * `Rate::fromPercentage('19')->percentage()` → `'19.0000000000'`.]
     *
     * That verbosity is DELIBERATE and must not be "tidied", which is why the correction is here rather than in the
     * emission. `docs/spec/pricing-vectors.json` § `conventions.rates` mandates exactly ten decimal places as the
     * one cross-tier spelling of a rate, and `PricingVectorsTest` asserts the raw string against that — because
     * round 15 found that a tier emitting `19` and a tier emitting `19.0000000000` for the same required table BOTH
     * passed, each normalising the declared value before comparing, which made the fixture's own spelling
     * unobservable. Trimming the key here would put the API back on the wrong side of that finding and would also
     * stop a client matching a key against `lines[].vatRate`, which carries the same form from the same method.
     *
     * A typed loop rather than `array_map`, because `array_map` preserving string keys is true but not *provable*
     * to a static analyser from the callback alone — and the keys are the meaningful identity here (they are what a
     * tax return aggregates by), so losing them silently would be worse than a slightly longer method.
     *
     * @return array<string, string>
     */
    private static function amountsByRate(DocumentTotals $totals): array
    {
        $byRate = [];

        // `vatByRate()` yields `VatGroup` objects, not `Money` — each carries its rate, its BASE and its vat, and
        // the base is what makes the group auditable. Only the vat is on the wire today; a client needing the base
        // is a contract addition, not a guess to make here.
        foreach ($totals->vatByRate() as $group) {
            $byRate[$group->rate()->percentage()] = $group->vat()->amount();
        }

        return $byRate;
    }
}
