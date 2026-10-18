<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module's report, declared from inside its own directory as it declares its imports and what it puts on « À surveiller »
 * (docs/SPEC.md § 7, 2026-09-20 03:40). The engine gives every report the same period, the same comparison and the same
 * permission check; a report says only what it shows and reads it.
 *
 * Its figures are read, never recomputed: a report sums the amounts the documents stored and re-derives no tax and no
 * rounding, so it can never disagree with the document it summarises.
 */
#[AutoconfigureTag('app.report.declarations')]
interface DeclaresReport
{
    /**
     * The documents a row lists at most: `documents` reads one more, so the screen can say there are more than it shows
     * and send the person to the list or the export.
     */
    public const int DOCUMENTS_SHOWN = 200;

    /** Stable, lowercase: what the address and the file name say (`takings`, `aged-balance`). */
    public function key(): string;

    /**
     * The key of the module it belongs to: switched off, the report is not offered. Null for a report of the core, such
     * as a country's tax reports, which belong to its fiscal preset rather than to a module.
     */
    public function module(): ?string;

    /** The permission that reads its subject on screen: a report shows nobody what the subject's own list would not. */
    public function permission(): string;

    /** Whether its figures can be narrowed to one establishment: an expense, for one, belongs to none. */
    public function byEstablishment(): bool;

    /**
     * Whether it reads a period. One that does not reads the company as it stands today, as an aged balance does: what is
     * owed now, by how late; it takes no period and no comparison, and the screen asks for neither.
     */
    public function periodic(): bool;

    /**
     * Its columns, for this member: a cost or a margin is a column only for whoever may read costs. The first column
     * names the row.
     *
     * @return list<ReportColumn>
     */
    public function columns(ReportContext $context): array;

    /**
     * Its rows over one period, in the order it shows them. A row's key is what makes it the same row in another period
     * (a customer's id, a method, a day's place in the period), so the comparison is read beside it.
     *
     * @return list<ReportRow>
     */
    public function rows(ReportContext $context, ReportPeriod $period): array;

    /**
     * The documents behind one row's figures, for a row whose drill-down says so, in the order they are read: what
     * makes the figure checkable, at most DOCUMENTS_SHOWN + 1 of them. Empty for a key it does not know.
     *
     * @return list<ReportDocument>
     */
    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array;
}
