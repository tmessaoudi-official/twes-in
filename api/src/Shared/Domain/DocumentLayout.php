<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The built-in layouts a printed document takes (docs/SPEC.md § 7, 2026-10-06 10:19). A layout is a stylesheet over the
 * one content every document prints, so no layout can leave a legal mention out.
 */
enum DocumentLayout: string
{
    /** Ruled and plain, as documents always printed. */
    case Classic = 'classic';
    /** The title, the table's head and the total on bands of the accent. */
    case Modern = 'modern';
    /** Smaller and tighter, for long documents. */
    case Compact = 'compact';
}
