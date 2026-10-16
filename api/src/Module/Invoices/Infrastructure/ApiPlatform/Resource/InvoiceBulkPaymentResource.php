<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Invoices\Application\Bulk\CheckInvoicesInBulk;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Module\Invoices\Infrastructure\ApiPlatform\State\Processor\InvoiceBulkPaymentProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Encaisser » on a list's selection, for whoever may record payments: the rows a check to pay included, each with the
 * amount it showed, paid by one method on one day. All or nothing: `bulk_changed` (409) when an invoice changed since
 * the check, a closed day or a bad body 422. Answers how many payments were recorded and their total.
 */
#[ApiResource(
    shortName: 'InvoiceBulkPayment',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/bulk-payments',
            processor: InvoiceBulkPaymentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 200,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class InvoiceBulkPaymentResource
{
    public const string READ = 'invoice_bulk_payment:read';
    public const string WRITE = 'invoice_bulk_payment:write';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(callback: [PaymentResource::class, 'methods'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $method = '';

    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $date = '';

    #[Assert\Length(max: PaymentDetails::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $reference = null;

    /** @var list<array{id: string, amount: string}> the rows the check included, in its order, each with its amount */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => CheckInvoicesInBulk::MOST,
        'items' => [
            'type' => 'object',
            'required' => ['id', 'amount'],
            'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'amount' => ['type' => 'string', 'example' => '250.500']],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, max: CheckInvoicesInBulk::MOST, groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'id' => [new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(strict: false, groups: [self::WRITE])],
        'amount' => [new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])],
    ], groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $rows = [];

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $recorded = 0;

    /** With three decimals. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $total = '0.000';
}
