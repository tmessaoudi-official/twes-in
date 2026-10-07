<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module says what of its own can be exported as a file, from inside its own directory, the way it declares its
 * import (docs/SPEC.md § 7, 2026-09-17: an export of every list, with the filters, search and sort it shows).
 * Every implementation is collected into the one catalogue, so adding a list never touches the export engine.
 */
#[AutoconfigureTag('app.export.declarations')]
interface DeclaresExport
{
    /** Stable, lowercase, plural: what the URL and the file name say — `customers`, `products`. */
    public function key(): string;

    /** The permission that reads the list on screen, so a file shows nobody anything the list would not. */
    public function permission(): string;

    /**
     * The key of the module the list belongs to: switched off, its list cannot be exported either. Null for a list of
     * the core, such as the activity journal, which no company can switch off.
     */
    public function module(): ?string;

    /** @return list<string> the header row, for THIS company: its preset's registration numbers, its own custom fields */
    public function columns(Company $company): array;

    /**
     * Every row the list holds under what the screen asked, in the screen's order, one at a time so that a long list
     * never sits in memory whole. A row has one cell per column, in the columns' order, each already text.
     *
     * @return iterable<int, list<string>>
     */
    public function rows(Company $company, ExportQuery $query): iterable;
}
