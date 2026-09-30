<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Watch\Application\WatchItem;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): what the signed-in member should look at in the company now,
 * read with company.read, each condition shown only to a role that may read its subject. Worked out on every read;
 * the home shows its count.
 */
#[ApiResource(
    shortName: 'Watch',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/watch',
            provider: WatchProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class WatchResource
{
    public const string READ = 'watch:read';

    /**
     * How many conditions of one kind an answer carries. A company grows its late customers with its customers (4437
     * items, 871 KB at 100k invoices, read by the home for a count), so the answer is a page of each kind and `count`
     * stays the whole. The cap is per kind, never overall: the catalogue lists the invoices before the stock, so an
     * overall cap would fill with late customers and drop every expired lot.
     */
    public const int MAX_PER_KIND = 50;

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $count = 0;

    /** @var list<array{kind: string, subjectId: ?string, params: array<string, string|int>}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['kind', 'subjectId', 'params'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'subjectId' => ['type' => ['string', 'null']],
                'params' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];

    /** @param list<WatchItem> $items */
    public static function of(array $items): self
    {
        $resource = new self();
        $resource->count = \count($items);
        $resource->items = array_map(static fn (WatchItem $item): array => ['kind' => $item->kind, 'subjectId' => $item->subjectId, 'params' => $item->params], self::pageOfEachKind($items));

        return $resource;
    }

    /**
     * @param list<WatchItem> $items
     *
     * @return list<WatchItem> the first MAX_PER_KIND of each kind, in the order given
     */
    private static function pageOfEachKind(array $items): array
    {
        $seen = [];
        $page = [];
        foreach ($items as $item) {
            $seen[$item->kind] = ($seen[$item->kind] ?? 0) + 1;
            if ($seen[$item->kind] <= self::MAX_PER_KIND) {
                $page[] = $item;
            }
        }

        return $page;
    }
}
