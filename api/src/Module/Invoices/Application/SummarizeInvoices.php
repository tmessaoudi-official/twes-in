<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;

/**
 * The home page's figures (docs/SPEC.md § 8 row 35), worked out on the company's own day, never the server's.
 *
 * - To collect: the amount due of every issued or partly paid invoice. A credit note is not counted on its own: what it
 *   corrects is already off its invoice's amount due. Late means due before today; due today is not late.
 * - To chase: the late invoices, the latest first, then those due within a week, the soonest first.
 * - Collected: the payments by the month of their day, which is already a company day.
 * - VAT: the VAT-family taxes of the documents issued this month, credit notes included, so a correction nets out. It
 *   is the VAT invoiced, not the VAT cashed: the basis a company declares on is not modelled yet.
 *
 * Every invoice of the company is read to sum them. That is what a proof of concept needs; a company with years of
 * invoices wants a projection in the repository instead.
 */
final readonly class SummarizeInvoices
{
    private const int CHASE_AHEAD_DAYS = 7;
    private const int CHASE_SHOWN = 4;
    private const int MONTHS = 6;
    /** The upper bound in days late of each bucket after `not_due`; the last has none. */
    private const array BUCKETS = ['days_1_15' => 15, 'days_16_30' => 30, 'days_31_45' => 45, 'days_over_45' => null];

    public function __construct(private InvoiceRepository $invoices, private ClockInterface $clock, private CurrencyScales $scales)
    {
    }

    public function handle(Company $company): InvoiceSummary
    {
        $scale = $this->scales->of($company->getCurrency());
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));
        $documents = $this->invoices->ofCompany($company->getId());

        $notYetDue = $overdue = $chaseAmount = Decimal::zero();
        $aging = ['not_due' => [Decimal::zero(), 0]] + array_map(static fn (): array => [Decimal::zero(), 0], self::BUCKETS);
        $overdueCount = 0;
        $oldest = null;
        $chase = [];
        $collected = [];
        for ($back = self::MONTHS - 1; $back >= 0; --$back) {
            $collected[$today->modify('first day of this month')->modify(\sprintf('-%d months', $back))->format('Y-m')] = Decimal::zero();
        }
        $vat = [];
        $thisMonth = $today->format('Y-m');

        foreach ($documents as $document) {
            $figures = $document->getIssuedFigures();
            if (null === $figures || InvoiceStatus::Cancelled === $document->getStatus()) {
                continue;
            }
            if ($document->getIssueDate()?->format('Y-m') === $thisMonth) {
                $this->addVat($vat, $document, $figures->taxes);
            }
            if (InvoiceType::Invoice !== $document->getType()) {
                continue;
            }
            foreach ($document->getPayments() as $payment) {
                $month = $payment->getDate()->format('Y-m');
                if (isset($collected[$month])) {
                    $collected[$month] = $collected[$month]->add(Decimal::of($payment->getAmount()));
                }
            }
            if (!\in_array($document->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)) {
                continue;
            }
            $due = Decimal::of($figures->amountDue);
            $dueDate = $document->getDueDate();
            $daysLate = null === $dueDate ? null : (int) $dueDate->diff($today)->format('%r%a');
            $bucket = self::bucket($daysLate);
            $aging[$bucket] = [$aging[$bucket][0]->add($due), $aging[$bucket][1] + 1];
            if ('not_due' === $bucket) {
                $notYetDue = $notYetDue->add($due);
            } else {
                $overdue = $overdue->add($due);
                ++$overdueCount;
                $oldest = max($oldest ?? 0, (int) $daysLate);
            }
            if (null !== $dueDate && null !== $daysLate && $daysLate >= -self::CHASE_AHEAD_DAYS) {
                $chaseAmount = $chaseAmount->add($due);
                $chase[] = [
                    'invoiceId' => $document->getId()->toRfc4122(),
                    'number' => (string) $document->getNumber(),
                    'customerName' => $document->getCustomerSnapshot()->name ?? '',
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'amountDue' => Decimal::format($due, $scale),
                    'daysLate' => $daysLate,
                ];
            }
        }
        usort($chase, static fn (array $a, array $b): int => [$b['daysLate'], $a['number']] <=> [$a['daysLate'], $b['number']]);
        uasort($vat, static fn (array $a, array $b): int => Decimal::of($b['rate'])->compare(Decimal::of($a['rate'])) ?: $a['code'] <=> $b['code']);

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
            \array_slice($chase, 0, self::CHASE_SHOWN),
            \count($chase),
            $amount($chaseAmount),
            array_map(static fn (string $month, Number $sum): array => ['month' => $month, 'amount' => $amount($sum)], array_keys($collected), $collected),
            array_values(array_map(static fn (array $tax): array => ['code' => $tax['code'], 'rate' => $tax['rate'], 'amount' => $amount($tax['amount'])], $vat)),
            $amount(Decimal::sum(array_values(array_map(static fn (array $tax): Number => $tax['amount'], $vat)))),
        );
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

    /**
     * Adds a document's VAT-family taxes, told apart by the components its lines charge: what issuing wrote keeps only
     * each tax's code.
     *
     * @param array<string, array{code: string, rate: string, amount: Number}>      $vat
     * @param list<array{code: string, rate: string, base: string, amount: string}> $taxes
     */
    private function addVat(array &$vat, Invoice $document, array $taxes): void
    {
        $vatCodes = [];
        foreach ($document->getLines() as $line) {
            foreach ($line->getTaxes() as $tax) {
                if (TaxFamily::Vat === $tax->getTaxComponent()->getFamily()) {
                    $vatCodes[$tax->getCode()] = true;
                }
            }
        }
        foreach ($taxes as $tax) {
            if (!isset($vatCodes[$tax['code']])) {
                continue;
            }
            $key = $tax['code'].'@'.$tax['rate'];
            $vat[$key] = ['code' => $tax['code'], 'rate' => $tax['rate'], 'amount' => ($vat[$key]['amount'] ?? Decimal::zero())->add(Decimal::of($tax['amount']))];
        }
    }
}
