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
use ApiPlatform\Metadata\Post;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Domain\PaymentMethod;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a customer has to their credit (docs/SPEC.md § 7): money received that no invoice took, kept for them and
 * applied to an invoice later (POST .../invoices/{invoiceId}/apply-credit). Read with `customer.read` and
 * `invoice.read`; recording money received takes `payment.write`, and answers the balance as it stands.
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
        new Post(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/credit-balance',
            processor: DepositCustomerCreditProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CustomerCreditBalanceResource
{
    public const string READ = 'customer_credit_balance:read';
    public const string WRITE = 'customer_credit_balance:write';
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];

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
     * The movements, newest first. `kind` is `deposit` (money received, above zero) or `applied` (credit paid into the
     * invoice `invoiceId`, below zero).
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
                'kind' => ['type' => 'string', 'enum' => ['deposit', 'applied']],
                'amount' => ['type' => 'string'],
                'reference' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'invoiceId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $entries = [];

    /** The day the money was received, YYYY-MM-DD, at the company's today at the latest. */
    #[ApiProperty(readable: false, schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $date = '';

    /** Above 0, in the currency's decimals. */
    #[ApiProperty(readable: false, schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '250.500'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $amount = '';

    /** A transfer or check number, say. */
    #[ApiProperty(readable: false)]
    #[Assert\Length(max: PaymentDetails::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $reference = null;

    #[ApiProperty(readable: false)]
    #[Assert\Length(max: PaymentDetails::NOTES_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $notes = null;

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

    /** @throws \App\Module\Invoices\Domain\InvalidInvoice */
    public function details(): PaymentDetails
    {
        return new PaymentDetails(new \DateTimeImmutable($this->date, new \DateTimeZone('UTC')), $this->amount, PaymentMethod::Other, $this->reference, $this->notes);
    }

    private static function scaled(string $amount, int $scale): string
    {
        return \App\Fiscal\Domain\Calculation\Decimal::format(\App\Fiscal\Domain\Calculation\Decimal::of($amount), $scale);
    }
}
