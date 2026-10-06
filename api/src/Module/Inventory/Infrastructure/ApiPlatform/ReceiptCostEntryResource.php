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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The cost of a receipt left « à compléter » by someone who could not read costs, entered by a cost reader with
 * stock.write and product.cost.read (docs/SPEC.md § 7, audit 2026-10-06 C challenge 9). The answer is the receipt; a
 * movement of nobody, or a caller without either permission, answers 404; a receipt whose cost is known, 409; a cost
 * that is not an amount, 422.
 */
#[ApiResource(
    shortName: 'ReceiptCostEntry',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/stock-movements/{movementId}/cost',
            status: 200,
            processor: EnterReceiptCostProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: StockMovementResource::class,
            normalizationContext: ['groups' => [StockMovementResource::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class ReceiptCostEntryResource
{
    public const string WRITE = 'receipt_cost_entry:write';

    /** What one unit cost, up to four decimals. */
    #[ApiProperty(schema: ['type' => 'string', 'maxLength' => 16, 'example' => '1200.5'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Type('string', groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $unitCost = '';

    /** Where the company leaves the choice to the receipt: whether the product now costs this or the new average. */
    #[Assert\Choice(choices: ['average', 'last'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $applyCost = null;
}
