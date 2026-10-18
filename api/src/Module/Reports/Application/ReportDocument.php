<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * One document behind a figure: what it is (`invoice`, `credit_note`, `expense`), which, its number and party as the
 * document recorded them, its day and the amount it brings to the figure, which is not always its total (a payment, one
 * product's lines).
 */
final readonly class ReportDocument
{
    public function __construct(
        public string $kind,
        public string $id,
        public ?string $number,
        public ?string $party,
        public string $day,
        public string $amount,
    ) {
    }
}
