<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Products\Infrastructure\ApiPlatform\State\Processor\SettlePriceReviewProcessor;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The margin a receipt's cost moved under a kept price, settled by someone who writes products and reads costs:
 * `adjust` sets the price that keeps the margin the product had (its `priceReview.suggestedPrice`), `keep` keeps the
 * price, `restore` puts back the price a cost moved away when the price followed it (« Annuler »). The answer is the
 * product. Without either permission, or for a product of nobody, 404; a product saved since it was read, 409
 * `/problems/record-changed`; one whose margin nothing moved, or with no followed price to put back, 409.
 */
#[ApiResource(
    shortName: 'ProductPriceReview',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/products/{productId}/price-review',
            status: 200,
            processor: SettlePriceReviewProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: ProductResource::class,
            normalizationContext: ['groups' => [ProductResource::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
    ],
)]
final class ProductPriceReviewResource
{
    public const string WRITE = 'product_price_review:write';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['adjust', 'keep', 'restore']])]
    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Choice(choices: ['adjust', 'keep', 'restore'], groups: [self::WRITE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::WRITE])]
    public string $decision = '';

    /** The version of the product the review was read at. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([RecordVersion::REVISE])]
    public ?int $version = null;
}
