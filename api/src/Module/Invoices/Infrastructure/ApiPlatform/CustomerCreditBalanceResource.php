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
use App\Module\Invoices\Domain\CustomerCreditEntry;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * What a customer has to their credit (docs/SPEC.md § 7): money paid beyond an invoice (POST
 * .../invoices/{invoiceId}/overpayments), kept for them and applied to an invoice later (POST
 * .../invoices/{invoiceId}/apply-credit). Read with `customer.read` and `invoice.read`.
 */
#[ApiResource(
    shortName: 'CustomerCreditBalance',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/credit-balance',
            provider: CustomerCreditBalanceProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
    ],
)]
final class CustomerCreditBalanceResource
{
    public const string READ = 'customer_credit_balance:read';
    public const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $customerId = '';

    /** What the customer has to their credit, at the currency's scale. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $balance = '0';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    /**
     * The movements, newest first. `kind` is `overpayment` (money paid beyond the invoice `invoiceId`, above zero),
     * `applied` (credit paid into the invoice `invoiceId`, below zero), `credited` or `refunded`.
     *
     * @var list<array{id: string, date: string, kind: string, amount: string, reference: ?string, notes: ?string, invoiceId: ?string}>
     */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'date', 'kind', 'amount', 'reference', 'notes', 'invoiceId'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'date' => ['type' => 'string', 'format' => 'date'],
                'kind' => ['type' => 'string', 'enum' => ['overpayment', 'applied', 'credited', 'refunded']],
                'amount' => ['type' => 'string'],
                'reference' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'invoiceId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $entries = [];

    /**
     * @param list<CustomerCreditEntry> $entries
     */
    public static function of(string $customerId, string $balance, string $currency, int $scale, array $entries): self
    {
        $resource = new self();
        $resource->customerId = $customerId;
        $resource->balance = $balance;
        $resource->currency = $currency;
        $resource->entries = array_map(static fn (CustomerCreditEntry $entry): array => [
            'id' => $entry->getId()->toRfc4122(),
            'date' => $entry->getDate()->format('Y-m-d'),
            'kind' => $entry->getKind()->value,
            'amount' => self::scaled($entry->getAmount(), $scale),
            'reference' => $entry->getReference(),
            'notes' => $entry->getNotes(),
            'invoiceId' => $entry->getInvoiceId()?->toRfc4122(),
        ], $entries);

        return $resource;
    }

    private static function scaled(string $amount, int $scale): string
    {
        return \App\Fiscal\Domain\Calculation\Decimal::format(\App\Fiscal\Domain\Calculation\Decimal::of($amount), $scale);
    }
}
