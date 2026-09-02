<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * Assert that `Invoice`'s edit-time probe -- `totals(PerLine, Up)` -- is MAXIMAL: that it refuses exactly the
 * documents some in-scope configuration cannot total, and accepts exactly those every configuration can.
 *
 * WHY THIS EXISTS, AND WHY IT IS A SCRIPT RATHER THAN A SENTENCE.
 *
 * `Invoice::totallable()` guards a money invariant: a draft may never hold a line the document cannot later be
 * totalled with, because the alternative is an unrenderable legal document holding a spent number. It probes ONE
 * configuration and claims that one is the worst case. Round 5 found the previous claim INVERTED -- it named
 * `PerRateGroup` as dominant when `ceil(a) + ceil(b) >= ceil(a+b)` makes `PerLine` dominant under `Up` -- so the
 * guard had been probing the SMALLER configuration, and a document could pass the edit and be impossible to total
 * under a rounding point a tenant setting selects.
 *
 * The fix was measured rather than reasoned, over 44 912 cases. **That measurement then existed nowhere.** It was
 * a one-off run, quoted as a number in this docblock and in `docs/SPEC.md`, reproducible by nobody -- which is
 * `CLAUDE.md` § Gotchas 2026-07-29's `[Verified against live roles]` shape, landing on the load-bearing numerical
 * claim of a money guard. Round 6 filed it (correctness lens F3). This script is the answer, in the same spirit as
 * `scripts/dev/assert-reconciliation-anchors.sh`: the claim is now a command.
 *
 * NOT A GATE, and deliberately not in `composer gate`: it takes minutes rather than seconds, and it guards a
 * property of the DOMAIN's arithmetic rather than a per-commit invariant. Run it after any change to
 * `Money::MAX_INTEGER_DIGITS`, `Decimal::rescale`, `DocumentCalculator::allocate()`, `DocumentTotals`'s accessor
 * set, or `totallable()` itself -- each can move the property without moving either of the two documents pinned
 * in `DocumentTotalsTest`. Runtime is noted by the script itself on every run, because a slow script nobody runs
 * is the same artefact it replaces.
 *
 * WHAT IT CHECKS.
 *
 *   under-refusal  the probe ACCEPTED a document that some in-scope configuration cannot total.  <- the R5C-1 bug
 *   over-refusal   the probe REFUSED a document that every in-scope configuration can total.     <- worse than the bug
 *
 * The second direction matters as much as the first: a guard tightened until it rejects valid documents is worse
 * than the defect it fixed. Note it is *nearly* tautological -- `(PerLine, Up)` is itself one of the enumerated
 * configurations, so it cannot over-refuse relative to a set containing it. It is checked anyway, because the day
 * `totallable()` probes something outside the set (a synthesised bound, a cheaper approximation) the tautology
 * stops holding and this is the line that notices.
 *
 * `RoundingMode::Unnecessary` IS EXCLUDED, matching `totallable()`'s own documented scope: it raises the same
 * exception type for a different reason -- not that a figure is too large, but that it needs rounding at all and
 * the caller forbade it -- so it constrains the INPUTS, not the total, and no magnitude probe can satisfy it.
 *
 * `\Throwable` is caught rather than `InvalidMoneyAmount`, deliberately: the shape the probe structurally CANNOT
 * see is a `\LogicException` from `allocate()` on the `PerRateGroup` path, which the `PerLine` probe never
 * executes. Catching the narrow type would make this script blind to exactly the failure it is best placed to find.
 *
 * EVERY ACCESSOR on the result is touched, not `total()` alone: each is its own `Money` construction and can
 * overflow independently, which is why the round-5 reproduction needed the aggregate rather than the sum.
 *
 * Exit 0 when the probe is maximal over the whole sweep; exit 1 naming every document that breaks it.
 */

use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\Exception\DocumentCannotBeTotalled;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\DocumentCalculator;
use Twes\Domain\Document\DocumentTotals;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

$root = \dirname(__DIR__, 2);
$autoload = $root . '/api/vendor/autoload.php';

// FAIL rather than skip when an input is missing -- the call `gate:schema` and `gate:licences` make. A maximality
// check that quietly passes because it could not load the domain is worse than one that is openly owed.
if (!is_readable($autoload)) {
    fwrite(\STDERR, "assert-totallable-probe-is-maximal: FAIL -- cannot read {$autoload}\n");
    fwrite(\STDERR, "Run `composer install` in api/ first; see docs/SPEC.md § 6.\n");

    exit(1);
}

require $autoload;

// **A `catch` NAMING A CLASS THAT DOES NOT EXIST NEVER MATCHES, AND PHP SAYS NOTHING.** The first draft of this
// script caught `Twes\Domain\Document\DocumentCannotBeTotalled`; the real class is under `...\Exception\`.
// The catch below silently matched nothing, every refusal escaped as a fatal, and the run before that one
// reported OK over 23 150 documents because the sabotage had not yet been applied. Asserted rather than trusted,
// because the failure mode is a green sweep that proves nothing -- the shape this whole script exists to retire.
foreach ([DocumentCannotBeTotalled::class, Invoice::class, DocumentCalculator::class] as $required) {
    if (!class_exists($required)) {
        fwrite(\STDERR, "assert-totallable-probe-is-maximal: FAIL -- {$required} is not loadable.\n");

        exit(1);
    }
}

/**
 * The in-scope configuration set: every rounding point x every mode that ROUNDS.
 *
 * Derived from the enums rather than hand-listed, so a new `VatRoundingPoint` or `RoundingMode` widens the sweep
 * automatically instead of leaving a configuration silently unprobed -- the hand-written-enumeration defect this
 * repository keeps paying for.
 *
 * @return list<array{VatRoundingPoint, RoundingMode}>
 */
function inScopeConfigurations(): array
{
    $configurations = [];

    foreach (VatRoundingPoint::cases() as $point) {
        foreach (RoundingMode::cases() as $mode) {
            if (RoundingMode::Unnecessary === $mode) {
                continue;
            }

            $configurations[] = [$point, $mode];
        }
    }

    return $configurations;
}

/**
 * Touch EVERY accessor, so an overflow in any one of them counts as "cannot be totalled".
 *
 * `get_class_methods` rather than a written list: an accessor added to `DocumentTotals` after this script joins
 * the sweep without anybody remembering to add it here. That is the whole difference between this and the
 * one-off run it replaces.
 */
function totalsUnder(array $lines, array $charges, Currency $currency, VatRoundingPoint $point, RoundingMode $mode): void
{
    $totals = new DocumentCalculator()->calculate($lines, $charges, $point, $mode, $currency);

    foreach (get_class_methods($totals) as $accessor) {
        $reflected = new ReflectionMethod($totals, $accessor);

        if ($reflected->isStatic() || 0 !== $reflected->getNumberOfRequiredParameters()) {
            continue;
        }

        $value = $totals->{$accessor}();

        // Force the string construction too: a `Money` can be built and still fail to render.
        if ($value instanceof Money) {
            $value->amount();
        }
    }
}

/**
 * @return iterable<string, array{list<DocumentLine>, list<FixedCharge>, Currency}>
 */
function documents(): iterable
{
    $currencies = ['TND', 'JPY', 'EUR', 'CLF'];
    $rates = ['0', '7', '19', '5.5', '250'];

    // Magnitudes hugging the 15-integer-digit bound from both sides, which is the only region where the two
    // rounding points can disagree about representability. A sweep over ordinary amounts proves nothing here:
    // the R5C-1 window is ONE QUANTUM wide.
    $units = [
        '947867298578199.012',
        '947867298578199.999',
        '999999999999999.999',
        '999999999999999.998',
        '499999999999999.999',
        '0.039',
        '0.001',
        '1.5',
    ];
    $quantities = ['1', '2', '0.5', '3.25'];
    $charges = [null, '0.100', '999999999999999.999'];

    foreach ($currencies as $currencyCode) {
        $currency = Currency::of($currencyCode);

        foreach ($rates as $rateA) {
            foreach ($rates as $rateB) {
                foreach ($units as $unitA) {
                    foreach ($units as $unitB) {
                        foreach ($quantities as $quantity) {
                            foreach ($charges as $charge) {
                                $document = buildDocument($currency, $rateA, $rateB, $unitA, $unitB, $quantity, $charge);

                                if (null === $document) {
                                    continue;
                                }

                                yield \sprintf(
                                    '%s|%s+%s|%s+%s|q%s|c%s',
                                    $currencyCode,
                                    $rateA,
                                    $rateB,
                                    $unitA,
                                    $unitB,
                                    $quantity,
                                    $charge ?? '-',
                                ) => $document;
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Builds the PARTS rather than an `Invoice`, which is the whole reason this sweep can see anything.
 *
 * `Invoice::draft()->withLine()` runs `totallable()` on every edit, so a document the probe refuses can never be
 * constructed through the aggregate -- and a sweep that can only build documents the probe ACCEPTS is
 * structurally incapable of finding an under-refusal. It would be green by construction, which is the vacuity
 * shape this repository keeps paying for. So the sweep drives `DocumentCalculator` directly: it is exactly what
 * `Invoice::totals()` delegates to, with the same arguments, and no test-only escape hatch has to be added to a
 * domain class to reach it.
 *
 * Returns null when the INPUT is refused for a reason that is not about the total's magnitude -- a quantity
 * whose scale the currency cannot express, a rate the domain rejects. Those are not cases about the probe.
 *
 * @return array{list<DocumentLine>, list<FixedCharge>, Currency}|null
 */
function buildDocument(
    Currency $currency,
    string $rateA,
    string $rateB,
    string $unitA,
    string $unitB,
    string $quantity,
    ?string $charge,
): ?array {
    try {
        $lines = [
            new DocumentLine($quantity, Money::of($unitA, $currency), Rate::fromPercentage($rateA)),
            new DocumentLine($quantity, Money::of($unitB, $currency), Rate::fromPercentage($rateB)),
        ];

        $charges = null === $charge ? [] : [new FixedCharge('Stamp duty', Money::of($charge, $currency))];

        return [$lines, $charges, $currency];
    } catch (\Throwable) {
        return null;
    }
}

$configurations = inScopeConfigurations();
$started = microtime(true);

$documentsSwept = 0;
$evaluations = 0;
$underRefusals = [];
$overRefusals = [];

foreach (documents() as $id => [$lines, $charges, $currency]) {
    ++$documentsSwept;

    $someConfigurationFails = false;

    foreach ($configurations as [$point, $mode]) {
        ++$evaluations;

        try {
            totalsUnder($lines, $charges, $currency, $point, $mode);
        } catch (\Throwable) {
            $someConfigurationFails = true;
        }
    }

    // **THE REAL GUARD, not a re-spelling of it.** An earlier draft of this script probed the literal
    // `(PerLine, Up)` here, which certifies a CONSTANT: reverting `totallable()` to the R5C-1 `PerRateGroup`
    // would have left this script green while the bug was back. So the document is built through the aggregate's
    // own mutators, and `probeRefuses` is whether `Invoice` ACTUALLY refuses it — whatever configuration
    // `totallable()` happens to probe. That is what makes the sabotage below fail.
    $probeRefuses = false;

    try {
        $draft = Invoice::draft($currency);

        foreach ($lines as $line) {
            $draft = $draft->withLine($line);
        }

        foreach ($charges as $charge) {
            $draft = $draft->withFixedCharge($charge);
        }
    } catch (DocumentCannotBeTotalled) {
        $probeRefuses = true;
    }

    if ($someConfigurationFails && !$probeRefuses) {
        $underRefusals[] = $id;
    }

    if (!$someConfigurationFails && $probeRefuses) {
        $overRefusals[] = $id;
    }
}

$elapsed = microtime(true) - $started;

// ANTI-VACUITY. A run that swept nothing must not report success -- that is how a check survives a refactor which
// silently stops feeding it, and this repository has been bitten by that shape more than once. The floors are
// deliberately far below what the enumeration above produces: they catch "the generator yields nothing", not
// "somebody tuned the inputs".
if ($documentsSwept < 1000 || $evaluations < 10000) {
    fwrite(\STDERR, \sprintf(
        "assert-totallable-probe-is-maximal: FAIL -- swept %d document(s) in %d evaluation(s); the enumeration\n"
        . "produced almost nothing, so an OK here would have meant nothing.\n",
        $documentsSwept,
        $evaluations,
    ));

    exit(1);
}

// And the sweep must actually REACH the interesting region. A sweep of documents no configuration can fail is
// green by construction and proves the probe is maximal only over inputs that could never test it.
if ([] === $underRefusals && [] === $overRefusals) {
    printf(
        "assert-totallable-probe-is-maximal: counts — documents=%d evaluations=%d configurations=%d elapsed=%.1fs\n",
        $documentsSwept,
        $evaluations,
        \count($configurations),
        $elapsed,
    );
    printf(
        "assert-totallable-probe-is-maximal: OK — `PerLine, Up` refused exactly the documents some configuration\n"
        . "could not total, over %d document(s): zero under-refusals, zero over-refusals.\n",
        $documentsSwept,
    );

    exit(0);
}

fwrite(\STDERR, \sprintf(
    "assert-totallable-probe-is-maximal: FAIL — %d under-refusal(s) and %d over-refusal(s) over %d document(s).\n",
    \count($underRefusals),
    \count($overRefusals),
    $documentsSwept,
));

foreach (\array_slice($underRefusals, 0, 20) as $id) {
    fwrite(\STDERR, "  UNDER (probe accepted, some configuration cannot total): {$id}\n");
}

foreach (\array_slice($overRefusals, 0, 20) as $id) {
    fwrite(\STDERR, "  OVER  (probe refused, every configuration can total):    {$id}\n");
}

fwrite(\STDERR, "\nAn UNDER-refusal means `totallable()` is probing a configuration that is not the worst case, "
    . "which is\nthe R5C-1 defect returning. An OVER-refusal means the guard now rejects valid documents, which "
    . "is worse.\nEither way the probe in Invoice::totallable() is no longer maximal — do not widen this script's "
    . "\nexclusions to make it green.\n");

exit(1);
