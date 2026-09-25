<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

use App\Tenancy\Domain\Company;

/**
 * A module says what of its own is worth watching, from inside its own directory, the way it declares its settings and
 * its imports (docs/SPEC.md § 7, 2026-09-24 12:10). What it answers is true NOW, worked out on every read and never
 * stored, so a condition dealt with leaves « À surveiller » by itself.
 */
interface DeclaresWatch
{
    /** Stable, lowercase: the prefix of every kind it answers (`invoices`, `stock`), and the order of the list. */
    public function key(): string;

    /** The key of the module it belongs to: switched off, its conditions are not shown. */
    public function module(): string;

    /** The permission that reads its subject: a member whose role lacks it is shown none of its conditions. */
    public function permission(): string;

    /**
     * Each condition true on the company's day, most pressing first within a kind. One statement per kind at most:
     * the home reads the count on every load.
     *
     * @return list<WatchItem>
     */
    public function itemsFor(Company $company, \DateTimeImmutable $today): array;
}
