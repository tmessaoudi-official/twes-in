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
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Invoices\Application\CustomerStatement;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * A customer's statement of account (docs/SPEC.md § 7): its invoices, credit notes and payments over a period, each
 * with what the customer owed after it. Read with `customer.read` and `invoice.read` together. With no period it runs
 * from the start of the company's year to its today.
 */
#[ApiResource(
    shortName: 'CustomerStatement',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/statement',
            provider: CustomerStatementProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
            parameters: [
                'from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The first day of the period, YYYY-MM-DD.'),
                'to' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The last day of the period, YYYY-MM-DD.'),
            ],
        ),
    ],
)]
final class CustomerStatementResource
{
    public const string READ = 'customer_statement:read';
    private const array TEXT = ['type' => 'string'];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $customerId = '';

    #[Groups([self::READ])]
    public string $customerName = '';

    #[Groups([self::READ])]
    public string $customerNumber = '';

    #[Groups([self::READ])]
    public string $currency = '';

    #[Groups([self::READ])]
    public int $currencyScale = 2;

    /** The first and the last day of the period, YYYY-MM-DD. */
    #[Groups([self::READ])]
    public string $from = '';

    #[Groups([self::READ])]
    public string $to = '';

    /** What the customer owed at the start of the period. */
    #[Groups([self::READ])]
    public string $openingBalance = '0';

    #[Groups([self::READ])]
    public string $totalDebit = '0';

    #[Groups([self::READ])]
    public string $totalCredit = '0';

    /** What the customer owed at the end of the period: the opening balance plus the debits less the credits. */
    #[Groups([self::READ])]
    public string $closingBalance = '0';

    /** What the customer may owe before a delivery warns: theirs, else their group's, else the company's; zero is no limit. */
    #[Groups([self::READ])]
    public string $creditLimit = '0';

    /** Whether what the customer owes at the end of the period is more than their limit; never with no limit. */
    #[Groups([self::READ])]
    public bool $overCreditLimit = false;

    /**
     * What happened in the period, in order. `kind` is `invoice`, `credit_note` or `payment`; a payment names the
     * invoice it settles by that invoice's number and carries its own reference when it has one.
     *
     * @var list<array{day: string, kind: string, number: string, documentId: string, reference: ?string, debit: string, credit: string, balance: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['day', 'kind', 'number', 'documentId', 'reference', 'debit', 'credit', 'balance'],
            'properties' => [
                'day' => self::TEXT,
                'kind' => ['type' => 'string', 'enum' => ['invoice', 'credit_note', 'payment']],
                'number' => self::TEXT,
                'documentId' => self::TEXT,
                'reference' => ['type' => ['string', 'null']],
                'debit' => self::TEXT,
                'credit' => self::TEXT,
                'balance' => self::TEXT,
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $lines = [];

    public static function of(CustomerStatement $statement): self
    {
        $resource = new self();
        $resource->customerId = $statement->customerId;
        $resource->customerName = $statement->customerName;
        $resource->customerNumber = $statement->customerNumber;
        $resource->currency = $statement->currency;
        $resource->currencyScale = $statement->currencyScale;
        $resource->from = $statement->from;
        $resource->to = $statement->to;
        $resource->openingBalance = $statement->openingBalance;
        $resource->totalDebit = $statement->totalDebit;
        $resource->totalCredit = $statement->totalCredit;
        $resource->closingBalance = $statement->closingBalance;
        $resource->creditLimit = $statement->creditLimit;
        $resource->overCreditLimit = $statement->overCreditLimit;
        $resource->lines = $statement->lines;

        return $resource;
    }
}
