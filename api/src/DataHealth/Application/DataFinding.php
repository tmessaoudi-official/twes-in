<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Application;

use Symfony\Component\Uid\Uuid;

/**
 * One thing a check found. The detail names what to look at (a document's number, a table and column) for whoever
 * runs the check on the server; the operators are told only the check, the company and how many were found, since
 * they keep no standing access to a company's data.
 */
final readonly class DataFinding
{
    /** @param Uuid|null $companyId null for what belongs to no company */
    public function __construct(
        public string $check,
        public ?Uuid $companyId,
        public string $detail,
    ) {
    }
}
