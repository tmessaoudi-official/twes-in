<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Module\Invoices\Application\CustomerAccountToday;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * A customer's running account as it stands at the end of the company's today: what their invoices have due, how much
 * of it is late, what they hold on account and where that leaves them against their credit limit. The statement ends on
 * the same figures. Read with `customer.read` and `invoice.read` together.
 */
#[ApiResource(
    shortName: 'CustomerAccount',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/account',
            provider: CustomerAccountProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class CustomerAccountResource
{
    public const string READ = 'customer_account:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $customerId = '';

    #[Groups([self::READ])]
    public string $currency = '';

    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** The company's today the account stands at, YYYY-MM-DD. */
    #[Groups([self::READ])]
    public string $day = '';

    /** What the customer's issued documents and payments come to: what their invoices have due. */
    #[Groups([self::READ])]
    public string $balance = '0';

    /** The part of the balance on invoices whose due day has passed; due today is not late. */
    #[Groups([self::READ])]
    public string $overdue = '0';

    #[Groups([self::READ])]
    public int $overdueCount = 0;

    /** How many days the longest-late invoice is past its due day; null when none is late. */
    #[Groups([self::READ])]
    public ?int $oldestOverdueDays = null;

    /** What the customer left with the company and no invoice took yet: theirs against what they owe. */
    #[Groups([self::READ])]
    public string $onAccount = '0';

    /** The balance less what is on account: what the credit limit is weighed against. */
    #[Groups([self::READ])]
    public string $owed = '0';

    /** Theirs, else their group's, else the company's; zero is no limit. */
    #[Groups([self::READ])]
    public string $creditLimit = '0';

    /** Whether what is owed is more than the limit; never with no limit. */
    #[Groups([self::READ])]
    public bool $overCreditLimit = false;

    public static function of(CustomerAccountToday $account): self
    {
        $resource = new self();
        $resource->customerId = $account->customerId;
        $resource->currency = $account->currency;
        $resource->currencyScale = $account->currencyScale;
        $resource->day = $account->day;
        $resource->balance = $account->balance;
        $resource->overdue = $account->overdue;
        $resource->overdueCount = $account->overdueCount;
        $resource->oldestOverdueDays = $account->oldestOverdueDays;
        $resource->onAccount = $account->onAccount;
        $resource->owed = $account->owed;
        $resource->creditLimit = $account->creditLimit;
        $resource->overCreditLimit = $account->overCreditLimit;

        return $resource;
    }
}
