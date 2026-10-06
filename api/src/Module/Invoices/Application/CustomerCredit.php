<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;

/**
 * Where a customer stands against their credit limit: the limit that applies to them (their own, else their group's,
 * else the company's) and what they owe today across their issued invoices, after credit notes and payments, less what
 * they hold on account: money they left with the company is theirs against what they owe.
 */
final readonly class CustomerCredit
{
    public function __construct(private StatementSource $source, private CustomerCreditRepository $balances, private ReadSetting $settings, private ClockInterface $clock)
    {
    }

    /** What the customer may owe before a delivery warns; zero is no limit. */
    public function limit(Company $company, Customer $customer): \BcMath\Number
    {
        $limit = $this->settings->value(new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId()), 'credit.limit');

        return Decimal::of(\is_string($limit) ? $limit : '0');
    }

    /** What the customer owes at the end of the company's today. */
    public function owed(Company $company, Customer $customer): \BcMath\Number
    {
        $tomorrow = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'))->modify('+1 day');

        return Decimal::of($this->source->balanceBefore($company->getId(), $customer->getId(), $tomorrow))
            ->sub(Decimal::of($this->balances->balance($company->getId(), $customer->getId())));
    }
}
