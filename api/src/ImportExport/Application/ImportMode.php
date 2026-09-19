<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** Whether a row that names an existing thing updates it, or is refused (docs/SPEC.md § 7, 2026-09-17). */
enum ImportMode: string
{
    /** Only new things: a row naming one that exists is rejected, so an import can never change what is there. */
    case Create = 'create';
    /** New things are created and existing ones updated from the cells the row fills in. */
    case Upsert = 'upsert';
}
