<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the import screen shows beside the template download: this company's file for one subject, column by column,
 * as a person reads it (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19). Asked of whoever may import the subject, and
 * as unknown as the subject itself where its module is off.
 */
#[ApiResource(
    shortName: 'ImportGuide',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/imports/{subject}',
            requirements: ['subject' => '[a-z-]+'],
            provider: ImportGuideProvider::class,
            security: 'is_granted("ROLE_USER")',
            // skip_null_values off: every column carries the same keys, a heading key or a label, never both.
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
        ),
    ],
)]
final class ImportGuideResource
{
    public const string READ = 'import_guide:read';

    /** What is imported, as its path names it. */
    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $subject = '';

    /** The column a row is found again by on a second import. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $identity = '';

    /** The most rows one file may hold, its header excluded; a longer file is refused whole. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $maxRows = 0;

    /** @var list<ImportGuideColumn> */
    #[ApiProperty(required: true, schema: [
        'type' => 'array',
        'description' => 'In the order the template writes them.',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'required', 'headingKey', 'label', 'example', 'noteKey'],
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'The header cell the file carries, whatever its language.'],
                'required' => ['type' => 'boolean', 'description' => 'Whether the file must have this column, and every row a value in it.'],
                'headingKey' => ['type' => ['string', 'null'], 'description' => 'A key of the screen’s catalogue to translate; null when `label` is set.'],
                'label' => ['type' => ['string', 'null'], 'description' => 'The heading already in the person’s words; null when `headingKey` is set.'],
                'example' => ['type' => ['string', 'null'], 'description' => 'A value in the shape expected, shown beside the download and never in the file.'],
                'noteKey' => ['type' => ['string', 'null'], 'description' => 'A key of the screen’s catalogue saying how to fill the column in.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $columns = [];
}
