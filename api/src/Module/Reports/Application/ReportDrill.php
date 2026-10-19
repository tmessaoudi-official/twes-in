<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * Where a row's figures lead (every figure opens the documents behind it). A list of
 * the app (`invoices`) narrowed by the query parameters its own address takes, when those say exactly which documents make
 * the figure; with no list, the report's own documents behind the row, when no list can.
 */
final readonly class ReportDrill
{
    /** @param array<string, string|list<string>> $filters the list's own query parameters */
    public function __construct(public ?string $list = null, public array $filters = [])
    {
        if (null === $list && [] !== $filters) {
            throw new \LogicException('Filters narrow a list; the documents behind a row take none.');
        }
    }
}
