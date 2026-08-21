<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Support;

use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Domain\Settings\CompanySettingsRepository;

/**
 * One company's settings, held in a property. The unit suite's {@see CompanySettingsRepository}.
 *
 * **IT HOLDS A CONFIGURABLE `CompanySettings`, NOT A CONSTANT ONE, and that is not gold-plating.** The handler
 * tests it serves already needed two different widths — one case issues at 3 and the rest at 7, deliberately, so
 * that a rendered number proves the pattern came from configuration rather than from a value that happens to match
 * everywhere. A double hard-wired to the defaults would have made that case unwritable, and a double that returns
 * the defaults is also the one shape guaranteed not to distinguish "the wiring works" from "the wiring is absent
 * and the default matched" — the vacuity `CLAUDE.md` § Gotchas records against a fixture whose expected value
 * equals what the code would produce anyway.
 *
 * **IT DOES NOT SIMULATE THE TENANT OR THE TRANSACTION**, which the real adapter refuses without. Both refusals are
 * about a database this class does not have, and reproducing them here would be a second implementation of a rule
 * whose whole point is that PostgreSQL enforces it. They are covered where they are real:
 * `ConfiguredSettingsAreHonouredTest` drives the actual adapter through the container against a live database, and
 * the adapter's own refusal messages are asserted there rather than imitated here. A double that invents its own
 * version of a security control is how the two drift.
 */
final class InMemoryCompanySettingsRepository implements CompanySettingsRepository
{
    private CompanySettings $settings;

    public function __construct(?CompanySettings $settings = null)
    {
        $this->settings = $settings ?? CompanySettings::defaults();
    }

    /**
     * The shape most call sites want: "this company renders numbers this wide, everything else is default."
     */
    public static function withNumberWidth(int $width): self
    {
        return new self(CompanySettings::defaults()->withNumberPattern(NumberPattern::padded($width)));
    }

    public static function withRoundingPoint(VatRoundingPoint $roundingPoint): self
    {
        return new self(CompanySettings::defaults()->withDefaultVatRoundingPoint($roundingPoint));
    }

    public function forCurrentTenant(): CompanySettings
    {
        return $this->settings;
    }

    public function save(CompanySettings $settings): void
    {
        $this->settings = $settings;
    }
}
