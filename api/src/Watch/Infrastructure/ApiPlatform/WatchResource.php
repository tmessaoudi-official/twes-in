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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * « À surveiller » in summary (docs/SPEC.md § 7, the subject pages): what the signed-in member should look at in the
 * company now, as one count per subject, read with company.read and each subject shown only to a role that may read
 * it. It never carries a row: the home and the bell read the count on every load, and a company's late customers grow
 * with its customers (4437 rows, 871 KB at 100k invoices). A subject's rows are `WatchRowResource`, a page at a time.
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

    /** Every row of every subject: the number on the home. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $count = 0;

    /** @var list<array{kind: string, count: int}> the subjects with something in them, most pressing first */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['kind', 'count'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $subjects = [];

    /** @param list<array{kind: string, count: int}> $subjects */
    public static function of(array $subjects): self
    {
        $resource = new self();
        $resource->subjects = $subjects;
        $resource->count = array_sum(array_column($subjects, 'count'));

        return $resource;
    }
}
