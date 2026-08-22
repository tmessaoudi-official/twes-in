<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * The one thing about this enum that a literal elsewhere depends on: how wide a column holding it must be.
 *
 * `CompanySettingsRow` mapped `default_vat_rounding_point` as `length: 32` while `Version20260820120000`
 * derived `varchar(14)` from these very cases — a mapping and a schema disagreeing about one column. Nothing
 * caught it: `doctrine:schema:validate --skip-sync` cannot, because `--skip-sync` is exactly what stops it
 * comparing against a database, and this project passes it deliberately (the migration adds row-level security,
 * CHECK constraints and composite keys no mapping expresses). So the mismatch had no automatic detector, and
 * the remedy is one constant both sides read.
 *
 * A constant rather than a derivation because an attribute argument must be a constant expression — and a
 * literal written beside the thing it measures is, per `CLAUDE.md`, the first thing to drift. This is what
 * stops it: adding a case with a longer backed value fails HERE, loudly, rather than at an INSERT in production.
 */
#[CoversClass(VatRoundingPoint::class)]
final class VatRoundingPointTest extends TestCase
{
    public function testTheDeclaredMaximumLengthIsTheLongestBackedValue(): void
    {
        $longest = 0;

        foreach (VatRoundingPoint::cases() as $case) {
            $longest = max($longest, \strlen($case->value));
        }

        self::assertSame(
            $longest,
            VatRoundingPoint::MAX_BACKED_VALUE_LENGTH,
            'MAX_BACKED_VALUE_LENGTH must equal the longest backed value — a column mapped from it would '
            . 'otherwise be too narrow to hold a case, or wider than the schema the migration created.',
        );
    }

    /**
     * The bound is not merely large enough — it is EXACT, and both directions matter.
     *
     * Too small truncates or refuses a legitimate value. Too large is the defect that was actually there: a
     * mapping claiming 32 against a `varchar(14)` column, where the mapping is the artefact a future
     * `migrations:diff` would generate from, so the drift propagates into a migration rather than being caught.
     */
    public function testEveryBackedValueFitsWithinTheDeclaredMaximum(): void
    {
        foreach (VatRoundingPoint::cases() as $case) {
            self::assertLessThanOrEqual(
                VatRoundingPoint::MAX_BACKED_VALUE_LENGTH,
                \strlen($case->value),
                \sprintf('"%s" does not fit the column width declared for this enum', $case->value),
            );
        }
    }
}
