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

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twes\Domain\Document\DocumentTotals;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Shared\RoundingMode;
use Twes\UI\Http\ApiResource\FixedChargeResource;
use Twes\UI\Http\ApiResource\InvoiceLineResource;
use Twes\UI\Http\ApiResource\InvoiceResource;
use Twes\UI\Http\ApiResource\InvoiceTotalsResource;

/**
 * Translates a persisted `Invoice` into its HTTP representation.
 *
 * The transport-boundary twin of the Doctrine mapper: the aggregate cannot carry an `#[ApiResource]` attribute
 * because § Architecture forbids a framework dependency in `Domain/`, so something has to translate. Dependencies
 * point inward — this knows `Domain/`, and `Domain/` knows nothing about this.
 *
 * **THE ROUNDING MODE IS NOT A CHOICE MADE HERE.** `docs/spec/pricing-vectors.json` states it for every consumer:
 * *"half_up on every amount and every rate. There is NO per-case override."* Those vectors are the cross-tier
 * contract that stops Angular, Flutter and the server each inventing a rule, so this provider applies the same
 * mode the vectors do and passing anything else would put the server outside its own contract.
 *
 * **THE ROUNDING POINT, by contrast, COMES FROM THE DOCUMENT.** `PerRateGroup` versus `PerLine` is persisted per
 * document precisely so a company changing its setting cannot restate a document a client already holds — so it is
 * read from `DocumentIdentity` and never from configuration.
 *
 * @implements ProviderInterface<InvoiceResource>
 */
final readonly class InvoiceProvider implements ProviderInterface
{
    /**
     * The mode the cross-tier vectors mandate. A constant rather than a parameter: a configurable rounding mode
     * would be a way to serve numbers that disagree with the vectors every client validates against.
     */
    private const RoundingMode MODE = RoundingMode::HalfUp;

    public function __construct(private InvoiceRepository $invoices) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        $id = $uriVariables['id'] ?? null;

        // A NON-STRING ID IS A 404, NOT A 500. API Platform hands over whatever matched the route, and an
        // ill-formed id is a caller error rather than a server one — `error.not_found` per CLAUDE.md
        // § "Translation keys", where a transport-level refusal gets a transport-level key.
        if (!\is_string($id)) {
            throw new NotFoundHttpException('An invoice id must be a string.');
        }

        try {
            $persisted = $this->invoices->find($id);
        } catch (\InvalidArgumentException $malformed) {
            // THE REPOSITORY REFUSES AN ILL-FORMED ID BEFORE IT REACHES A QUERY, and that refusal is correct —
            // an id is a key, and two spellings of one key compare unequal. Translated to a 404 rather than a 400
            // deliberately: distinguishing "malformed" from "absent" tells an unauthenticated prober that its
            // guess had the right SHAPE, which is a small oracle for free. Both answers are "no such document".
            throw new NotFoundHttpException('No such invoice.', $malformed);
        }

        if (null === $persisted) {
            // NULL COVERS BOTH "does not exist" AND "belongs to another tenant", indistinguishably, and that is
            // the whole design of row-level security rather than a limitation of it. An error naming the document
            // would confirm its existence to a tenant not entitled to know.
            throw new NotFoundHttpException('No such invoice.');
        }

        return self::represent($persisted);
    }

    /**
     * VAT per rate group as decimal strings, keyed by the rate.
     *
     * A typed loop rather than `array_map`, because `array_map` preserving string keys is true but not *provable*
     * to a static analyser from the callback alone — and the keys are the meaningful identity here (`"19"`, `"7"`
     * are what an invoice prints and what a tax return aggregates by), so losing them silently would be worse than
     * a slightly longer method.
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

    private static function represent(PersistedInvoice $persisted): InvoiceResource
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
}
