<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Domain\CustomerRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's statement of account, on the company's own day. With no period it runs from the start of the company's
 * year to today. Each line carries what the customer owed after it, so the last line's balance is the closing one;
 * the debit and credit columns add up in the totals. The documents are summed where they are kept (StatementSource);
 * the running balance is worked out here, in exact decimals.
 */
final readonly class StatementOfAccount
{
    public function __construct(
        private StatementSource $source,
        private CustomerRepository $customers,
        private CurrencyScales $scales,
        private RunningAccount $account,
    ) {
    }

    /**
     * @throws CustomerNotFound
     * @throws InvalidStatementPeriod
     */
    public function handle(Company $company, Uuid $customerId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): CustomerStatement
    {
        $customer = $this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound();
        $to ??= $this->account->today($company);
        $from ??= $to->modify('first day of january this year');
        if ($from > $to) {
            throw new InvalidStatementPeriod('The period ends before it starts.');
        }

        $scale = $this->scales->of($company->getCurrency());
        $opening = Decimal::of($this->source->balanceBefore($company->getId(), $customerId, $from));
        $balance = $opening;
        $debits = $credits = Decimal::zero();
        $lines = [];
        foreach ($this->source->entries($company->getId(), $customerId, $from, $to) as $entry) {
            $amount = Decimal::of($entry['amount']);
            $debit = $amount->compare(0) > 0 ? $amount : Decimal::zero();
            $credit = $amount->compare(0) < 0 ? Decimal::zero()->sub($amount) : Decimal::zero();
            $balance = $balance->add($amount);
            $debits = $debits->add($debit);
            $credits = $credits->add($credit);
            $lines[] = [
                'day' => $entry['day']->format('Y-m-d'),
                'kind' => $entry['kind'],
                'number' => $entry['number'],
                'documentId' => $entry['documentId'],
                'reference' => $entry['reference'],
                'debit' => Decimal::format($debit, $scale),
                'credit' => Decimal::format($credit, $scale),
                'balance' => Decimal::format($balance, $scale),
            ];
        }

        $limit = $this->account->limit($company, $customer);
        // What the customer held at the period's end, not today: money put on account after it would clear a limit the
        // customer really had passed then.
        $onAccount = $this->account->onAccountAt($company, $customerId, $to);

        return new CustomerStatement(
            $customerId->toRfc4122(),
            $customer->getProfile()->name,
            $customer->getNumber(),
            $company->getCurrency(),
            $scale,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            Decimal::format($opening, $scale),
            Decimal::format($debits, $scale),
            Decimal::format($credits, $scale),
            Decimal::format($balance, $scale),
            Decimal::format($limit, $scale),
            RunningAccount::passes($limit, $balance->sub($onAccount)),
            Decimal::format($onAccount, $scale),
            $lines,
        );
    }
}
