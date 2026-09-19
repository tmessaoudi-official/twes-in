<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** Where a column's heading is written, so the column guide can hand a person words they read (docs/SPEC.md § 7). */
enum ImportHeading
{
    /** A key of the screen's own catalogue (`web/public/i18n`), which the screen translates. */
    case ScreenText;
    /** A key of the API's `fiscal` catalogue, the one a preset's labels live in, which the API translates. */
    case FiscalLabel;
    /** Words the company wrote itself, such as a custom field's label, shown as they are. */
    case Label;
}
