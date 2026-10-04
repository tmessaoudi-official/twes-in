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
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Module\Invoices\Domain\InstrumentDetails;
use App\Module\Invoices\Domain\InstrumentKind;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\PaymentInstrument;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A cheque or a traite received against an issued invoice (docs/SPEC.md § 7, 2026-09-21 18:40), received, moved on and
 * taken out of the portfolio with payment.write, listed with invoice.read. It is not a payment: the invoice stays due
 * until the instrument is cashed, which records the payment. An invoice that is not issued, or an instrument that
 * cannot take the step asked, answers 409; an amount above what is still due and not covered by another instrument, or
 * a due day before the issue day, 422.
 */
#[ApiResource(
    shortName: 'PaymentInstrument',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments',
            provider: InvoiceInstrumentsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            paginationEnabled: false,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments',
            processor: ReceiveInstrumentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/deposit',
            status: 200,
            processor: AdvanceInstrumentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
            extraProperties: ['step' => 'deposit'],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/cash',
            status: 200,
            processor: AdvanceInstrumentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
            extraProperties: ['step' => 'cash'],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/unpaid',
            status: 200,
            processor: AdvanceInstrumentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
            extraProperties: ['step' => 'unpaid'],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}',
            processor: DeleteInstrumentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class PaymentInstrumentResource
{
    public const string READ = 'payment_instrument:read';
    public const string WRITE = 'payment_instrument:write';
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['check', 'draft']])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(callback: [self::class, 'kinds'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $kind = '';

    /** Above 0, at most what is still due and not covered by another instrument, in the currency's decimals. */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '250.500'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $amount = '';

    /** The day it falls due, YYYY-MM-DD: from the invoice's issue day, any day after it. */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $dueOn = '';

    /** The bank it is drawn on. */
    #[Assert\Length(max: InstrumentDetails::BANK_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $bank = null;

    /** The cheque or traite number. */
    #[Assert\Length(max: InstrumentDetails::NUMBER_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['held', 'deposited', 'cashed', 'unpaid']])]
    #[Groups([self::READ])]
    public string $status = '';

    /** The day it was cashed or came back unpaid; null while it is open. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $settledOn = null;

    /** The payment cashing it recorded; null until it is cashed. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $paymentId = null;

    /** Who received it; null when no one signed in did. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $recordedBy = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'format' => 'date-time'])]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (InstrumentKind $kind): string => $kind->value, InstrumentKind::cases());
    }

    public static function of(PaymentInstrument $instrument): self
    {
        $resource = new self();
        $resource->id = $instrument->getId()->toRfc4122();
        $resource->kind = $instrument->getKind()->value;
        $resource->amount = $instrument->getAmount();
        $resource->dueOn = $instrument->getDueOn()->format('Y-m-d');
        $resource->bank = $instrument->getBank();
        $resource->number = $instrument->getNumber();
        $resource->status = $instrument->getStatus()->value;
        $resource->settledOn = $instrument->getSettledOn()?->format('Y-m-d');
        $resource->paymentId = $instrument->getPayment()?->getId()->toRfc4122();
        $resource->recordedBy = $instrument->getRecordedBy()?->toRfc4122();
        $resource->createdAt = $instrument->getCreatedAt()->format(\DATE_ATOM);

        return $resource;
    }

    /** @throws InvalidInvoice */
    public function details(): InstrumentDetails
    {
        return new InstrumentDetails(InstrumentKind::from($this->kind), new \DateTimeImmutable($this->dueOn, new \DateTimeZone('UTC')), $this->amount, $this->bank, $this->number);
    }
}
