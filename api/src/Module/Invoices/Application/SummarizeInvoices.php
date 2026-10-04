<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;

/**
 * The home page's figures, worked out on the company's own day, never the server's.
 *
 * - To collect: the amount due of every issued or partly paid invoice. A credit note is not counted on its own: what it
 *   corrects is already off its invoice's amount due. Late means due before today; due today is not late.
 * - To chase: the late invoices, the latest first, then those due within a week, the soonest first.
 * - Collected: the payments by the month of their day, which is already a company day.
 * - Withheld (retenue à la source suffered): what the documents issued this month kept back, credit notes netting theirs out.
 * - Invoiced and collected so far this month are read against the same days of last month; the margin is what the lines
 *   with a frozen cost sold for less that cost, shown only to a reader of costs.
 * - VAT: the VAT-family taxes of the documents issued this month, credit notes included, so a correction nets out. It
 *   is the VAT invoiced, not the VAT cashed: the basis a company declares on is not modelled yet.
 *
 * The database adds the amounts up (InvoiceSummarySource); which bucket, which month and what is late is decided here.
 */
final readonly class SummarizeInvoices
{
    private const int CHASE_AHEAD_DAYS = 7;
    private const int CHASE_SHOWN = 4;
    private const int MONTHS = 6;
    /** The upper bound in days late of each bucket after `not_due`; the last has none. */
    private const array BUCKETS = ['days_1_15' => 15, 'days_16_30' => 30, 'days_31_45' => 45, 'days_over_45' => null];

    public function __construct(private InvoiceSummarySource $source, private ClockInterface $clock, private CurrencyScales $scales)
    {
    }

    public function handle(Company $company, bool $withCosts = false): InvoiceSummary
    {
        $scale = $this->scales->of($company->getCurrency());
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));
        $thisMonth = $today->modify('first day of this month');

        $notYetDue = $overdue = $chaseAmount = Decimal::zero();
        $aging = ['not_due' => [Decimal::zero(), 0]] + array_map(static fn (): array => [Decimal::zero(), 0], self::BUCKETS);
        $overdueCount = $chaseCount = 0;
        $oldest = null;
        foreach ($this->source->openByDueDate($company->getId()) as $group) {
            $due = Decimal::of($group['amount']);
            $daysLate = self::daysLate($group['dueDate'], $today);
            $bucket = self::bucket($daysLate);
            $aging[$bucket] = [$aging[$bucket][0]->add($due), $aging[$bucket][1] + $group['count']];
            if ('not_due' === $bucket) {
                $notYetDue = $notYetDue->add($due);
            } else {
                $overdue = $overdue->add($due);
                $overdueCount += $group['count'];
                $oldest = max($oldest ?? 0, (int) $daysLate);
            }
            if (null !== $daysLate && $daysLate >= -self::CHASE_AHEAD_DAYS) {
                $chaseAmount = $chaseAmount->add($due);
                $chaseCount += $group['count'];
            }
        }
        $chase = array_map(static fn (array $row): array => [
            'invoiceId' => $row['invoiceId'],
            'number' => $row['number'],
            'customerName' => $row['customerName'],
            'dueDate' => $row['dueDate']->format('Y-m-d'),
            'amountDue' => Decimal::format(Decimal::of($row['amountDue']), $scale),
            'daysLate' => (int) self::daysLate($row['dueDate'], $today),
        ], $this->source->firstDueBy($company->getId(), $today->modify(\sprintf('+%d days', self::CHASE_AHEAD_DAYS)), self::CHASE_SHOWN));

        $collected = [];
        $firstMonth = $thisMonth->modify(\sprintf('-%d months', self::MONTHS - 1));
        $paid = $this->source->paidByMonth($company->getId(), $firstMonth);
        for ($back = self::MONTHS - 1; $back >= 0; --$back) {
            $month = $thisMonth->modify(\sprintf('-%d months', $back))->format('Y-m');
            $collected[$month] = Decimal::of($paid[$month] ?? '0');
        }

        $vat = $this->source->vatIssued($company->getId(), $thisMonth, $thisMonth->modify('+1 month'));
        usort($vat, static fn (array $a, array $b): int => Decimal::of($b['rate'])->compare(Decimal::of($a['rate'])) ?: $a['code'] <=> $b['code']);

        // The month so far and the same number of days of last month, which may be shorter: it stops where this month starts.
        $tomorrow = $today->modify('+1 day');
        $lastFrom = $thisMonth->modify('-1 month');
        $lastUntil = min($lastFrom->modify(\sprintf('+%d days', (int) $today->format('j'))), $thisMonth);
        $now = $this->source->invoicedBetween($company->getId(), $thisMonth, $tomorrow);
        $before = $this->source->invoicedBetween($company->getId(), $lastFrom, $lastUntil);

        $amount = static fn (Number $value): string => Decimal::format($value, $scale);

        return new InvoiceSummary(
            $company->getCurrency(),
            $scale,
            $today->format('Y-m-d'),
            $amount($notYetDue->add($overdue)),
            $amount($notYetDue),
            $amount($overdue),
            $overdueCount,
            $oldest,
            array_map(static fn (string $bucket, array $sum): array => ['bucket' => $bucket, 'amount' => $amount($sum[0]), 'count' => $sum[1]], array_keys($aging), $aging),
            $chase,
            $chaseCount,
            $amount($chaseAmount),
            array_map(static fn (string $month, Number $sum): array => ['month' => $month, 'amount' => $amount($sum)], array_keys($collected), $collected),
            array_map(static fn (array $tax): array => ['code' => $tax['code'], 'rate' => $tax['rate'], 'amount' => $amount(Decimal::of($tax['amount']))], $vat),
            $amount(Decimal::sum(array_map(static fn (array $tax): Number => Decimal::of($tax['amount']), $vat))),
            $amount(Decimal::of($now['net'])),
            $amount(Decimal::of($before['net'])),
            $amount(Decimal::of($this->source->paidBetween($company->getId(), $thisMonth, $tomorrow))),
            $amount(Decimal::of($this->source->paidBetween($company->getId(), $lastFrom, $lastUntil))),
            $withCosts && $now['costedLines'] > 0 ? $amount(Decimal::of($now['costedNet'])->sub(Decimal::of($now['cost']))) : null,
            $withCosts && $before['costedLines'] > 0 ? $amount(Decimal::of($before['costedNet'])->sub(Decimal::of($before['cost']))) : null,
            $withCosts && $now['costedLines'] > 0 ? $amount(Decimal::of($now['costedNet'])) : null,
            $withCosts,
            $amount(Decimal::of($this->source->withheldBetween($company->getId(), $thisMonth, $tomorrow))),
            $amount(Decimal::of($this->source->withheldBetween($company->getId(), $lastFrom, $lastUntil))),
        );
    }

    /** How many days before today a day was, negative when it is still to come; null without a day. */
    private static function daysLate(?\DateTimeImmutable $dueDate, \DateTimeImmutable $today): ?int
    {
        return null === $dueDate ? null : (int) $dueDate->diff($today)->format('%r%a');
    }

    /** `not_due` until the day after the due day; an invoice without a due day is never late. */
    private static function bucket(?int $daysLate): string
    {
        if (null === $daysLate || $daysLate <= 0) {
            return 'not_due';
        }
        foreach (self::BUCKETS as $bucket => $upTo) {
            if (null === $upTo || $daysLate <= $upTo) {
                return $bucket;
            }
        }

        throw new \LogicException('The last bucket has no bound.');
    }
}
