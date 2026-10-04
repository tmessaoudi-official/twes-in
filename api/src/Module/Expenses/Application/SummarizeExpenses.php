<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Expenses\Domain\ExpenseRepository;
use App\Shared\Application\MonthSoFar;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;

/**
 * What the company recorded in expenses so far this month, taxes included, beside the same days of last month, on the
 * company's own day. It is spending, never a profit: nothing here subtracts it from what was invoiced.
 */
final readonly class SummarizeExpenses
{
    public function __construct(private ExpenseRepository $expenses, private ClockInterface $clock, private CurrencyScales $scales)
    {
    }

    public function handle(Company $company): ExpenseSummary
    {
        $scale = $this->scales->of($company->getCurrency());
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));
        $window = MonthSoFar::of($today);
        $sum = fn (\DateTimeImmutable $from, \DateTimeImmutable $until): string => Decimal::format(Decimal::of($this->expenses->recordedGrossBetween($company->getId(), $from, $until)), $scale);

        return new ExpenseSummary(
            $company->getCurrency(),
            $scale,
            $today->format('Y-m-d'),
            $sum($window->from, $window->until),
            $sum($window->previousFrom, $window->previousUntil),
        );
    }
}
