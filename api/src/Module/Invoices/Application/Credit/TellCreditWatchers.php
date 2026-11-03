<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Credit;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Application\Statement\RunningAccount;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Shared\Application\Notification;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;

/**
 * Tells the people who issue a company's invoices that an invoice just took a customer past their credit limit (docs/
 * SPEC.md § 7, alerts rather than reports). Told when the account CROSSES the limit, not while it stays over, so a
 * customer already over it does not raise one alert for every invoice after.
 *
 * Decided inside the issuing transaction, the account held, and told once it commits: read afterwards, two invoices of
 * one customer issued at once each count the other as owed before them, and the crossing is told to nobody or twice.
 */
final readonly class TellCreditWatchers
{
    public const string PERMISSION = 'invoice.issue';
    public const string LIMIT_PASSED = 'invoice.credit_limit_passed';

    public function __construct(private RunningAccount $account, private MembershipRepository $memberships, private CreditAlerts $alerts, private CurrencyScales $scales, private CreditAccountsHeld $held)
    {
    }

    /** Called in the issuing transaction once the invoice is stored with its figures, after every other lock it takes. */
    public function whileIssuing(Company $company, Invoice $invoice): void
    {
        if (InvoiceType::Invoice !== $invoice->getType()) {
            return;
        }
        $customer = $invoice->getCustomer();
        $limit = $this->account->limit($company, $customer);
        if ($limit->compare(0) <= 0) {
            return;
        }
        // Two invoices of two series take no common lock before this one: read without it, each would find nothing
        // owed before it and tell the crossing twice, or neither would cross and nobody would be told. What is owed is
        // read in a statement after the hold, so it sees the other invoice once that one has committed.
        $this->held->hold($company->getId(), $customer->standingFor()->getId());
        $owed = $this->account->owed($company, $customer);
        $figures = $invoice->getIssuedFigures() ?? throw new \LogicException('An issued invoice has its figures.');
        $before = $owed->sub(Decimal::of($figures->amountDue));
        if (1 !== $owed->compare($limit) || 1 === $before->compare($limit)) {
            return;
        }
        $told = [];
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $told[] = new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), self::LIMIT_PASSED, [
                    'customer_id' => $customer->getId()->toRfc4122(),
                    'customer' => $customer->getProfile()->name,
                    'number' => $invoice->getNumber(),
                    'owed' => $owed->value,
                    'limit' => $limit->add('0.000')->value,
                    'currency' => $company->getCurrency(),
                    'scale' => $this->scales->of($company->getCurrency()),
                    'company' => $company->getName(),
                ], $company->getId()->toRfc4122());
            }
        }
        $this->alerts->onceCommitted(...$told);
    }
}
