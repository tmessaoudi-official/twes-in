<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Settings;

use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * One company's document configuration — the settings a tenant chooses once and every new document inherits.
 *
 * **THIS TYPE EXISTS TO END TWO HARDWIRES, and naming them is the point.** Until it landed,
 * `config/services.yaml` wired `$numberWidth: 7` into `IssueInvoiceHandler` and `CreateInvoiceProcessor`
 * passed `VatRoundingPoint::PerRateGroup` as a literal. Both carried a comment saying so and pointing at the
 * settings table Wave 1 owed; `build-waves.plan.md` § Wave 1 names that table as a deliverable. Neither was a
 * defect on its own — a placeholder with a visible shape is the honest way to defer configuration — but
 * together they made `PerLine` **unreachable over HTTP**, so half the calculation kernel this product is built
 * around could not be exercised by any caller.
 *
 * **WHY THE COUPLING TO `Domain\Document` IS CORRECT RATHER THAN TOLERATED.** This class lives in
 * `Domain\Settings` and imports two types from `Domain\Document`. That is an INWARD-neutral, same-layer
 * reference — `scripts/gates/layer-dependencies.php` refuses `Domain` → `Application`/`Infrastructure`/`UI`,
 * not `Domain` → `Domain` — and the direction is the meaningful one: settings are ABOUT documents, so settings
 * knowing what a `NumberPattern` is costs nothing, whereas `NumberPattern` knowing it is stored on a settings
 * row would be the coupling that matters. The alternative considered and rejected was putting this class in
 * `Domain\Document`: it would put a company-wide concern inside the document aggregate's namespace, and the
 * next setting to arrive (a default currency, a payment term) has nothing to do with documents at all.
 *
 * **THE DEFAULTS ARE CONSTANTS HERE AND NOWHERE ELSE.** The migration's column `DEFAULT`, the adapter's
 * missing-row behaviour, and the values the two retired wires used must all agree, and three copies of a
 * number is how they stop agreeing. `CLAUDE.md` § Gotchas records the general form under *"decide a condition
 * ONCE into a variable that every path reads"* — that entry is about a guard implemented on one write path and
 * not the other, and a default duplicated across a migration and an adapter is the same mistake with a
 * different surface. Both values reproduce the retired wires EXACTLY, which is what makes this change
 * behaviour-preserving for a tenant that has configured nothing:
 * {@see self::DEFAULT_NUMBER_PATTERN_WIDTH} was `services.yaml`'s `$numberWidth`, and
 * {@see self::defaultVatRoundingPointValue()} was `CreateInvoiceProcessor`'s literal.
 *
 * **IMMUTABLE, like every other domain type here.** The two `with…()` mutators return a new instance, so a
 * settings object handed to a calculation cannot be changed underneath it. That is also why the persistence
 * model is a separate mutable row class in `Infrastructure/` — see `CLAUDE.md` § Architecture for why
 * immutability, not the choice of mapping driver, is the load-bearing reason for that split.
 */
final readonly class CompanySettings
{
    /**
     * The width a company's document numbers are rendered at when it has chosen none — `0000041`.
     *
     * **Seven because that is what the retired wire used, not because seven is a good number.** Changing it
     * here would silently re-render nothing already issued (an issued document's rendered string is persisted
     * and re-reading derives its own pattern from that string — `CLAUDE.md` § Gotchas 2026-08-06), but it
     * WOULD change the width of the next number every unconfigured tenant is issued, which is a product
     * decision rather than a tidy-up. The value is bounded at both ends by `NumberPattern::padded()`, so a
     * bad edit here fails at construction rather than reaching a column.
     */
    public const int DEFAULT_NUMBER_PATTERN_WIDTH = 7;

    private function __construct(
        private NumberPattern $numberPattern,
        private VatRoundingPoint $defaultVatRoundingPoint,
    ) {}

    /**
     * Settings a company has actually chosen.
     */
    public static function of(NumberPattern $numberPattern, VatRoundingPoint $defaultVatRoundingPoint): self
    {
        return new self($numberPattern, $defaultVatRoundingPoint);
    }

    /**
     * What a company that has configured nothing gets.
     *
     * **A NAMED CONSTRUCTOR RATHER THAN NULLABLE PARAMETERS**, so that "unconfigured" is a state a caller has
     * to ask for by name. The adapter that returns this must first prove a tenant is bound — see
     * {@see CompanySettingsRepository::forCurrentTenant()} for why an unbound read and a missing row must
     * never be allowed to look alike.
     */
    public static function defaults(): self
    {
        return new self(
            NumberPattern::padded(self::DEFAULT_NUMBER_PATTERN_WIDTH),
            self::defaultVatRoundingPointValue(),
        );
    }

    /**
     * The rounding point an unconfigured company computes tax with.
     *
     * A method rather than a `const`, because PHP constant expressions cannot hold an enum case in a way the
     * migration's SQL can read back — the persisted form is the enum's BACKED VALUE, and
     * {@see self::defaultVatRoundingPointBackedValue()} is what the column `DEFAULT` must equal. Keeping both
     * shapes derived from one case is what stops the SQL and the PHP drifting apart.
     */
    public static function defaultVatRoundingPointValue(): VatRoundingPoint
    {
        return VatRoundingPoint::PerRateGroup;
    }

    /**
     * The same default as the string a database column stores, for the migration to cite.
     */
    public static function defaultVatRoundingPointBackedValue(): string
    {
        return self::defaultVatRoundingPointValue()->value;
    }

    public function numberPattern(): NumberPattern
    {
        return $this->numberPattern;
    }

    /**
     * The rounding point a NEW document is created with.
     *
     * **"DEFAULT" IS LOAD-BEARING IN THIS NAME.** A document's own rounding point is persisted per document
     * and is what its totals are computed with, forever — a company changing this setting must not restate a
     * document a client already holds (`build-waves.plan.md` § numbering, and the ruling of 2026-08-07 that a
     * client may not choose `vatRoundingPoint` at all because the two settings declare different tax on
     * identical lines). This value seeds that snapshot and never overrides it.
     */
    public function defaultVatRoundingPoint(): VatRoundingPoint
    {
        return $this->defaultVatRoundingPoint;
    }

    public function withNumberPattern(NumberPattern $numberPattern): self
    {
        return new self($numberPattern, $this->defaultVatRoundingPoint);
    }

    public function withDefaultVatRoundingPoint(VatRoundingPoint $defaultVatRoundingPoint): self
    {
        return new self($this->numberPattern, $defaultVatRoundingPoint);
    }
}
