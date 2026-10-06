<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Products\Application\PricePreview;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a net price being typed comes to with the line taxes being chosen, one line per quantity asked (a unit, each
 * pack), counted by the calculator every document uses, read with product.read. Nothing is saved: it is the price
 * calculator's with-tax preview, so the browser never guesses a compounding or a rounding. A tax that is not one of
 * the company's line taxes, a price or a quantity that is not one, answers 422.
 */
#[ApiResource(
    shortName: 'PricePreview',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/price-preview',
            status: 200,
            processor: PricePreviewProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class PricePreviewResource
{
    public const string READ = 'price_preview:read';
    public const string WRITE = 'price_preview:write';

    /** Net of tax, up to four decimals. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'maxLength' => 16, 'example' => '100.5'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Regex('/^\d{1,10}(\.\d{1,4})?$/', groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $unitPriceNet = '';

    /** @var list<string> the line taxes the product starts a line with */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 10])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(max: 10, groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string')], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $taxComponentIds = [];

    /** @var list<string> how many are on the line: 1 for a unit, a pack's count for a pack */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string', 'example' => '12'], 'minItems' => 1, 'maxItems' => 20])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, max: 20, groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^(?!0+(\.0+)?$)\d{1,8}(\.\d{1,3})?$/')], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $quantities = [];

    /** @var list<array{quantity: string, net: string, tax: string, total: string}> in the order the quantities were asked */
    #[ApiProperty(writable: false, required: true, schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['quantity', 'net', 'tax', 'total'], 'properties' => [
        'quantity' => ['type' => 'string'],
        'net' => ['type' => 'string', 'description' => 'Before tax, at the currency\'s scale.'],
        'tax' => ['type' => 'string', 'description' => 'Every line tax, at the currency\'s scale.'],
        'total' => ['type' => 'string', 'description' => 'What a customer pays, at the currency\'s scale.'],
    ]]])]
    #[Groups([self::READ])]
    public array $prices = [];

    /** @param list<PricePreview> $prices */
    public static function of(array $prices): self
    {
        $resource = new self();
        $resource->prices = array_map(static fn (PricePreview $price): array => ['quantity' => $price->quantity, 'net' => $price->net, 'tax' => $price->tax, 'total' => $price->total], $prices);

        return $resource;
    }
}
