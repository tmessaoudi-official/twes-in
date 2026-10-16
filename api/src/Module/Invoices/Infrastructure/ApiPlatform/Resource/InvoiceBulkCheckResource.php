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
use App\Module\Invoices\Application\Bulk\BulkAction;
use App\Module\Invoices\Application\Bulk\CheckInvoicesInBulk;
use App\Module\Invoices\Infrastructure\ApiPlatform\State\Processor\InvoiceBulkCheckProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a bulk action would do with the invoices a person selected, before it runs, for whoever may read invoices:
 * each id once, in the order sent, `included` or `skipped` with a `reason` the screen translates, and, to pay, the
 * `amount` each would receive on the `date` sent. Nothing is changed. More than a list page of ids, none, one that is
 * not an identifier, or a payment with no day or dated after today, 422.
 */
#[ApiResource(
    shortName: 'InvoiceBulkCheck',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/bulk-check',
            processor: InvoiceBulkCheckProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 200,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class InvoiceBulkCheckResource
{
    public const string READ = 'invoice_bulk_check:read';
    public const string WRITE = 'invoice_bulk_check:write';

    private const array ROWS = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'number', 'customerName', 'outcome', 'reason', 'amount'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'number' => ['type' => ['string', 'null']],
                'customerName' => ['type' => ['string', 'null']],
                'outcome' => ['type' => 'string', 'enum' => ['included', 'skipped']],
                'reason' => ['type' => ['string', 'null'], 'enum' => ['not_found', 'draft', 'cancelled', 'paid', 'credit_note', 'instrument_held', 'issued_after', null]],
                'amount' => ['type' => ['string', 'null'], 'description' => 'To pay: what the invoice would receive, all it still owes, with three decimals; null otherwise'],
            ],
        ],
    ];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['export', 'pdf', 'pay']])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(callback: [self::class, 'actions'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $action = '';

    /** @var list<string> the selected invoices' ids, a list page at most */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'minItems' => 1, 'maxItems' => CheckInvoicesInBulk::MOST])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, max: CheckInvoicesInBulk::MOST, groups: [self::WRITE])]
    #[Assert\All([new Assert\Uuid(strict: false)], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $ids = [];

    /** To pay: the day the payments would be dated, today at the latest. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $date = null;

    /** @var list<array{id: string, number: ?string, customerName: ?string, outcome: string, reason: ?string, amount: ?string}> */
    #[ApiProperty(writable: false, schema: self::ROWS)]
    #[Groups([self::READ])]
    public array $rows = [];

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $included = 0;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $skipped = 0;

    /** @return list<string> */
    public static function actions(): array
    {
        return array_column(BulkAction::cases(), 'value');
    }
}
