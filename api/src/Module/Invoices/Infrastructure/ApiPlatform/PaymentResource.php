<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Payment;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Module\Invoices\Domain\PaymentMethod;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A payment on an issued invoice, recorded and deleted with payment.write (docs/SPEC.md § 7, 2026-09-14). An invoice
 * that is not issued answers 409; an amount above what is due or finer than the currency, or a day outside the issue
 * day to the company's today, 422. The invoice lists its payments and answers what is still due.
 */
#[ApiResource(
    shortName: 'Payment',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/payments',
            processor: RecordPaymentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/payments/{paymentId}',
            processor: DeletePaymentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class PaymentResource
{
    public const string READ = 'payment:read';
    public const string WRITE = 'payment:write';
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The day the customer paid, YYYY-MM-DD: from the issue day to the company's today. */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $date = '';

    /** Above 0, at most what is still due, in the currency's decimals. */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '250.500'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $amount = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(callback: [self::class, 'methods'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $method = '';

    /** A transfer or check number, say. */
    #[Assert\Length(max: PaymentDetails::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $reference = null;

    #[Assert\Length(max: PaymentDetails::NOTES_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    /** Who recorded it; null when no one signed in did. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $recordedBy = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'format' => 'date-time'])]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /** @return list<string> */
    public static function methods(): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases());
    }

    public static function of(Payment $payment): self
    {
        $resource = new self();
        $resource->id = $payment->getId()->toRfc4122();
        $resource->date = $payment->getDate()->format('Y-m-d');
        $resource->amount = $payment->getAmount();
        $resource->method = $payment->getMethod()->value;
        $resource->reference = $payment->getReference();
        $resource->notes = $payment->getNotes();
        $resource->recordedBy = $payment->getRecordedBy()?->toRfc4122();
        $resource->createdAt = $payment->getCreatedAt()->format(\DATE_ATOM);

        return $resource;
    }

    /** @throws InvalidInvoice */
    public function details(): PaymentDetails
    {
        return new PaymentDetails(new \DateTimeImmutable($this->date, new \DateTimeZone('UTC')), $this->amount, PaymentMethod::from($this->method), $this->reference, $this->notes);
    }
}
