<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * The body of `PUT /api/settings`.
 *
 * **BOTH FIELDS ARE REQUIRED, because this is a PUT** — see {@see CompanySettingsResource} on why a PATCH's
 * "absent means keep" is the wrong shape for a setting that decides how much tax a document declares.
 *
 * ## Validation here is a MESSAGE-QUALITY feature, never the invariant
 *
 * `CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary" rules this explicitly: *"Validation at the edge is a
 * message-quality feature; the invariant lives in the value object. Both, not either."* Every bound declared below
 * is also enforced by {@see NumberPattern::padded()} and by {@see VatRoundingPoint}, and a third time by a `CHECK`
 * constraint on the column. Deleting these attributes would change the STATUS and the MESSAGE a client sees, never
 * whether a bad value can be stored.
 *
 * The bounds are DERIVED from the domain rather than written as literals, so a change to `NumberPattern::MAX_WIDTH`
 * cannot leave this DTO accepting a width the domain then refuses — which would turn a clean 422 naming the field
 * into a 422 carrying an internal message, the wrong-bound shape `CLAUDE.md` records for
 * `document.quantity_too_large`.
 *
 * **`Choice` over the enum's own cases rather than a mapped enum type**, matching how `DocumentRow` and
 * `CompanySettingsRow` treat the same value: the translation from the wire's string to the enum belongs in one
 * place, and that place is the processor, which is also the one that has to refuse a value the enum does not know.
 */
final readonly class CompanySettingsInput
{
    public function __construct(
        /**
         * How wide a NEW document number is rendered.
         *
         * An `int` on the wire and not a decimal string: the money rule (`CLAUDE.md` — every monetary and quantity
         * field is a decimal STRING) exists because `NUMERIC` re-scales and a float loses precision. A digit count
         * is neither; it is a small counting number, and declaring it a string would invent a project-specific
         * spelling of an ordinary integer — the first-principles marker § Gotchas 2026-08-02 warns about.
         */
        #[Assert\Range(min: 1, max: NumberPattern::MAX_WIDTH)]
        public int $numberPatternWidth,
        /** Where VAT is rounded on a NEW document. Existing documents keep the value they were computed with. */
        #[Assert\Choice(callback: [self::class, 'roundingPoints'])]
        public string $defaultVatRoundingPoint,
    ) {}

    /**
     * The permitted rounding points, derived from the enum rather than listed.
     *
     * A written list would be a fourth copy of a set that already exists in the enum, the CHECK constraint and the
     * adapter's refusal — and `CLAUDE.md` records at length that an enumeration written beside the thing it
     * enumerates is the first thing to drift.
     *
     * @return list<string>
     */
    public static function roundingPoints(): array
    {
        return array_map(static fn(VatRoundingPoint $point): string => $point->value, VatRoundingPoint::cases());
    }
}
