<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/** The calendar days a report reads, both ends included, in the company's own calendar. A plain value, as a port carries. */
final readonly class ReportPeriod
{
    /** At most a year and a day: a report reads documents, and a longer span is an export of the books, not a report. */
    public const int LONGEST_DAYS = 366;

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $this->from = $from->setTime(0, 0);
        $this->to = $to->setTime(0, 0);
        if ($this->to < $this->from) {
            throw new InvalidReportRequest('The period ends before it starts.');
        }
        if ($this->from->diff($this->to)->days >= self::LONGEST_DAYS) {
            throw new InvalidReportRequest(\sprintf('A report reads at most %d days.', self::LONGEST_DAYS));
        }
    }
}
