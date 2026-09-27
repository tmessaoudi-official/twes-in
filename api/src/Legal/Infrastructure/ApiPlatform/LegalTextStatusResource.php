<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Legal\Application\LegalTextStatus;
use Symfony\Component\Serializer\Attribute\Groups;

/** Every legal page in every language and where it stands, as the operator's legal screen lists them. */
#[ApiResource(
    shortName: 'LegalTextStatus',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/legal-texts',
            provider: LegalTextStatusProvider::class,
            security: 'is_granted("'.PlatformLegalTextResource::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
        ),
    ],
)]
final class LegalTextStatusResource
{
    public const string READ = 'legal_text_status:read';

    #[ApiProperty(identifier: false, writable: false, required: true)]
    #[Groups([self::READ])]
    public string $page = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $language = '';

    /** Whether the page has any version in this language. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public bool $written = false;

    /** The latest version's date; null when none. */
    #[ApiProperty(writable: false, required: true, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $publishedOn = null;

    /** Whether the latest version was validated. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public bool $validated = false;

    public static function of(LegalTextStatus $status): self
    {
        $resource = new self();
        $resource->page = $status->page->value;
        $resource->language = $status->language->value;
        $resource->written = null !== $status->latest;
        $resource->publishedOn = $status->latest?->getPublishedOn()->format('Y-m-d');
        $resource->validated = $status->latest?->isValidated() ?? false;

        return $resource;
    }
}
