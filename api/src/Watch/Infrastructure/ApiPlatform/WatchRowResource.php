<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Watch\Application\WatchItem;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * One row of one subject of « À surveiller », a page at a time (docs/SPEC.md § 7, the subject pages): the thing to act
 * on and its figures. A subject the member may not see, a module switched off and a kind nobody declared all answer
 * 404, so asking by name learns nothing.
 */
#[ApiResource(
    shortName: 'WatchRow',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/watch/{kind}',
            requirements: ['kind' => '[a-z_]+\.[a-z_]+'],
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: WatchRowProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class WatchRowResource
{
    public const string READ = 'watch_row:read';

    // No identifier: reached only through the uriTemplate above, and a row is not addressable by itself.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $kind = '';

    /** What the row is about, for the link to it: a customer's or a product's id. */
    #[ApiProperty(required: true, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ])]
    public ?string $subjectId = null;

    /** @var array<string, string|int> */
    #[ApiProperty(required: true, schema: ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]])]
    #[Groups([self::READ])]
    public array $params = [];

    public static function of(WatchItem $item): self
    {
        $resource = new self();
        $resource->kind = $item->kind;
        $resource->subjectId = $item->subjectId;
        $resource->params = $item->params;

        return $resource;
    }
}
