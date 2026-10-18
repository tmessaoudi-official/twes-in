<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * One column of a report: its key, the cell key of every row, what kind of figure it holds, and the numbers its heading
 * says, when the company chose them: an aged balance's « 31 to 60 days late » reads its days from the company's settings.
 */
final readonly class ReportColumn
{
    public const string KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** @param array<string, int> $params */
    public function __construct(public string $key, public ReportColumnKind $kind, public array $params = [])
    {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \LogicException(\sprintf('A report column is named in lowercase words: %s.', $key));
        }
    }
}
