<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;

/**
 * Tells the people who issue a company's invoices that an invoice just took a customer past their credit limit (docs/
 * SPEC.md § 7, alerts rather than reports). Told when the account CROSSES the limit, not while it stays over, so a
 * customer already over it does not raise one alert for every invoice after.
 */
final readonly class TellCreditWatchers
{
    public const string PERMISSION = 'invoice.issue';
    public const string LIMIT_PASSED = 'invoice.credit_limit_passed';

    public function __construct(private CustomerCredit $credit, private MembershipRepository $memberships, private Notifications $notifications)
    {
    }

    public function afterIssue(Company $company, Invoice $invoice): void
    {
        if (InvoiceType::Invoice !== $invoice->getType()) {
            return;
        }
        $customer = $invoice->getCustomer();
        $limit = $this->credit->limit($company, $customer);
        if ($limit->compare(0) <= 0) {
            return;
        }
        $owed = $this->credit->owed($company, $customer);
        $figures = $invoice->getIssuedFigures() ?? throw new \LogicException('An issued invoice has its figures.');
        $before = $owed->sub(Decimal::of($figures->amountDue));
        if (1 !== $owed->compare($limit) || 1 === $before->compare($limit)) {
            return;
        }
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $this->notifications->publish(new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), self::LIMIT_PASSED, [
                    'customer_id' => $customer->getId()->toRfc4122(),
                    'customer' => $customer->getProfile()->name,
                    'number' => $invoice->getNumber(),
                    'owed' => $owed->value,
                    'limit' => $limit->add('0.000')->value,
                    'currency' => $company->getCurrency(),
                    'company' => $company->getName(),
                ]));
            }
        }
    }
}
