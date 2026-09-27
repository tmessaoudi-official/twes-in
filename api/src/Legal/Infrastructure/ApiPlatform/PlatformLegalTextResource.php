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
use ApiPlatform\Metadata\Post;
use App\Legal\Domain\LegalText;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One version of a legal page in one language, as the platform's operators write, read back and validate it
 * (docs/SPEC.md § 8 row 148). Only an operator reaches any of it; anyone reads the latest at /api/legal.
 */
#[ApiResource(
    shortName: 'PlatformLegalText',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/legal-texts/{page}/{language}/versions',
            requirements: self::REQUIREMENTS,
            name: 'platform_legal_text_versions',
            provider: LegalTextVersionsProvider::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/legal-texts/{page}/{language}/versions',
            requirements: self::REQUIREMENTS,
            name: 'platform_legal_text_write',
            processor: WriteLegalTextProcessor::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/platform/legal-texts/{page}/{language}/validate',
            requirements: self::REQUIREMENTS,
            name: 'platform_legal_text_validate',
            status: 200,
            input: false,
            processor: ValidateLegalTextProcessor::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class PlatformLegalTextResource
{
    public const string READ = 'platform_legal_text:read';
    public const string WRITE = 'platform_legal_text:write';
    public const string PERMISSION = 'platform.legal.manage';
    public const array REQUIREMENTS = ['page' => '[a-z]+', 'language' => '[a-z]{2}'];

    // No identifier: reached only through the uriTemplates above (see PlatformCompanyResource).
    #[ApiProperty(identifier: false, writable: false, required: true)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $page = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $language = '';

    /** Markdown. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ, self::WRITE])]
    public string $body = '';

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'format' => 'date'])]
    #[Groups([self::READ])]
    public string $publishedOn = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public bool $validated = false;

    #[ApiProperty(writable: false, required: true, schema: ['type' => ['string', 'null'], 'format' => 'date-time'])]
    #[Groups([self::READ])]
    public ?string $validatedAt = null;

    /** The address of the operator who validated it. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public ?string $validatedBy = null;

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'format' => 'date-time'])]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /** The address of the operator who wrote it; null for a draft the platform shipped. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public ?string $createdBy = null;

    public static function of(LegalText $text): self
    {
        $resource = new self();
        $resource->id = $text->getId()->toRfc4122();
        $resource->page = $text->getPage()->value;
        $resource->language = $text->getLanguage()->value;
        $resource->body = $text->getBody();
        $resource->publishedOn = $text->getPublishedOn()->format('Y-m-d');
        $resource->validated = $text->isValidated();
        $resource->validatedAt = $text->getValidatedAt()?->format(\DATE_ATOM);
        $resource->validatedBy = $text->getValidatedBy();
        $resource->createdAt = $text->getCreatedAt()->format(\DATE_ATOM);
        $resource->createdBy = $text->getCreatedBy();

        return $resource;
    }
}
