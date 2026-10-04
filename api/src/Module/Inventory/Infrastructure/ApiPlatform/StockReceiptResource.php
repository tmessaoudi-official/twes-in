<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Inventory\Domain\StockLot;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One delivery shared out over several places, written with stock.write: a receipt per place, all stored or none, so
 * the stock at the places is always the sum of the movements and never part of a delivery. A place may appear once;
 * the same lot, cost and cost choice apply to every part, as they do to one receipt. A refusal answers 422 naming the
 * field. What one place receives alone still goes through the stock movements.
 */
#[ApiResource(
    shortName: 'StockReceipt',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/stock-receipts',
            processor: RecordStockReceiptProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class StockReceiptResource
{
    public const string READ = 'stock_receipt:read';
    public const string WRITE = 'stock_receipt:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $productId = '';

    /**
     * Where the delivery goes and how much of it, in the order the person gave them: the first is the main place.
     *
     * @var list<array{locationId: string, quantity: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['locationId', 'quantity'],
            'properties' => ['locationId' => ['type' => 'string', 'format' => 'uuid'], 'quantity' => ['type' => 'string']],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(
        fields: ['locationId' => [new Assert\NotBlank(groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], 'quantity' => [new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])]],
        groups: [self::WRITE],
    )], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $parts = [];

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => StockLot::CODE_MAX])]
    #[Groups([self::WRITE])]
    public ?string $lotCode = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $lotExpiresOn = null;

    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'enum' => ['average', 'last', null]])]
    #[Assert\Choice(choices: ['average', 'last'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $applyCost = null;

    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'maxLength' => 16])]
    #[Groups([self::WRITE])]
    public ?string $unitCost = null;

    /**
     * The movements written, one per part and in the same order. Read only.
     *
     * @var list<string>
     */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']])]
    #[Groups([self::READ])]
    public array $movementIds = [];
}
