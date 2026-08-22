<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Pricing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Pricing\PricedBy;

/**
 * The one thing about this enum that a column width depends on.
 *
 * `CLAUDE.md` § Gotchas records `CompanySettingsRow` mapping a column as `length: 32` while its migration
 * derived `varchar(14)` from the enum's own cases. Nothing caught it: `doctrine:schema:validate --skip-sync`
 * cannot, because `--skip-sync` is precisely what stops it comparing against a database, and this project passes
 * that flag deliberately — the migrations add row-level security, CHECK constraints and composite keys that no
 * mapping expresses. So this class of mismatch has no automatic detector, and the remedy is one constant that
 * both the mapping and the migration read.
 *
 * This exists BEFORE the `product` table does. Writing it after the two sides have already disagreed is how the
 * first instance happened.
 */
#[CoversClass(PricedBy::class)]
final class PricedByTest extends TestCase
{
    public function testTheDeclaredMaximumLengthIsTheLongestBackedValue(): void
    {
        $longest = 0;

        foreach (PricedBy::cases() as $case) {
            $longest = max($longest, \strlen($case->value));
        }

        self::assertSame(
            $longest,
            PricedBy::MAX_BACKED_VALUE_LENGTH,
            'MAX_BACKED_VALUE_LENGTH must equal the longest backed value — a column mapped from it would '
            . 'otherwise be too narrow to hold a case, or wider than the schema the migration created.',
        );
    }

    /**
     * The bound is EXACT, and both directions matter.
     *
     * Too small truncates or refuses a legitimate value. Too large is the defect that was actually found on
     * `CompanySettingsRow`, and it is the more insidious one: the mapping is the artefact a future
     * `migrations:diff` generates from, so an overstated width propagates INTO a migration rather than being
     * caught by one.
     */
    public function testEveryBackedValueFitsWithinTheDeclaredMaximum(): void
    {
        foreach (PricedBy::cases() as $case) {
            self::assertLessThanOrEqual(
                PricedBy::MAX_BACKED_VALUE_LENGTH,
                \strlen($case->value),
                \sprintf('"%s" does not fit the column width declared for this enum', $case->value),
            );
        }
    }

    /**
     * The BACKED VALUES themselves are pinned, because they are persisted and they cross the wire.
     *
     * Renaming a case is a PHP refactor; renaming its backed value is a data migration and a breaking API
     * change, which `CLAUDE.md` § "The API contract is ours to design" says must never be incidental. snake_case
     * matches the rest of the contract.
     */
    public function testTheBackedValuesAreTheContract(): void
    {
        self::assertSame('profit_rate', PricedBy::ProfitRate->value);
        self::assertSame('net_price', PricedBy::NetPrice->value);
    }
}
