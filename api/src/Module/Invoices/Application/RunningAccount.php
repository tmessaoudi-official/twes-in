<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's running account: the one reading of what they owe that the statement, the overdue figure, the credit
 * limit and a delivery's warning all take, so none of them can tell the customer a different story.
 *
 * - The balance is what their issued documents and payments come to at the end of a day: what their invoices have due.
 * - On account is what they left with the company by then and no invoice took; it is theirs against what they owe.
 * - Owed is the balance less what is on account, and it is what the limit is weighed against.
 * - Overdue is the part of the balance on invoices whose due day has passed, by the rule the invoice list's chip uses.
 */
final readonly class RunningAccount
{
    public function __construct(
        private StatementSource $source,
        private CustomerCreditRepository $balances,
        private InvoiceRepository $invoices,
        private ReadSetting $settings,
        private ClockInterface $clock,
        private CurrencyScales $scales,
    ) {
    }

    /** The company's own today, never the server's. */
    public function today(Company $company): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));
    }

    /** What the customer may owe before a delivery warns: theirs, else their group's, else the company's; zero is no limit. */
    public function limit(Company $company, Customer $customer): Number
    {
        $limit = $this->settings->value(new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId()), 'credit.limit');

        return Decimal::of(\is_string($limit) ? $limit : '0');
    }

    /** What the customer's issued documents and payments came to at the end of a day. */
    public function balanceAt(Company $company, Uuid $customerId, \DateTimeImmutable $day): Number
    {
        return Decimal::of($this->source->balanceBefore($company->getId(), $customerId, $day->modify('+1 day')));
    }

    /** What the customer held on account at the end of a day: money put there afterwards was not theirs to weigh yet. */
    public function onAccountAt(Company $company, Uuid $customerId, \DateTimeImmutable $day): Number
    {
        $held = Decimal::zero();
        foreach ($this->balances->entries($company->getId(), $customerId) as $entry) {
            if ($entry->getDate()->format('Y-m-d') <= $day->format('Y-m-d')) {
                $held = $held->add(Decimal::of($entry->getAmount()));
            }
        }

        return $held;
    }

    /** What the customer owes at the end of the company's today, less what they hold on account. */
    public function owed(Company $company, Customer $customer): Number
    {
        $today = $this->today($company);

        return $this->balanceAt($company, $customer->getId(), $today)->sub($this->onAccountAt($company, $customer->getId(), $today));
    }

    /** Whether what is owed is more than the limit; a limit of zero is none, whatever is owed. */
    public static function passes(Number $limit, Number $owed): bool
    {
        return $limit->compare(0) > 0 && $owed->compare($limit) > 0;
    }

    /** The account as it stands at the end of the company's today. */
    public function of(Company $company, Customer $customer): CustomerAccountToday
    {
        $today = $this->today($company);
        $scale = $this->scales->of($company->getCurrency());
        $balance = $this->balanceAt($company, $customer->getId(), $today);
        $onAccount = $this->onAccountAt($company, $customer->getId(), $today);
        $owed = $balance->sub($onAccount);
        $limit = $this->limit($company, $customer);
        $overdue = $this->invoices->overdueOf($company->getId(), $customer->getId(), $today);
        $oldest = $overdue['oldestDueDate'];

        return new CustomerAccountToday(
            $customer->getId()->toRfc4122(),
            $company->getCurrency(),
            $scale,
            $today->format('Y-m-d'),
            Decimal::format($balance, $scale),
            Decimal::format(Decimal::of($overdue['amount']), $scale),
            $overdue['count'],
            null === $oldest ? null : (int) $oldest->diff($today)->days,
            Decimal::format($onAccount, $scale),
            Decimal::format($owed, $scale),
            Decimal::format($limit, $scale),
            self::passes($limit, $owed),
        );
    }
}
