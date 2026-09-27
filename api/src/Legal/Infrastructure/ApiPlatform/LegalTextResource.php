<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A legal page as anyone reads it, signed in or not (docs/SPEC.md § 8 row 148): its latest version in the language
 * asked for, else in French, else in English, and which one it is. Public by the firewall (security.yaml).
 */
#[ApiResource(
    shortName: 'LegalText',
    operations: [
        new Get(
            uriTemplate: '/legal/{page}/{language}',
            requirements: ['page' => '[a-z]+', 'language' => '[a-z]{2}'],
            provider: LegalTextProvider::class,
            normalizationContext: ['groups' => [self::READ], 'preserve_empty_objects' => true],
        ),
    ],
)]
final class LegalTextResource
{
    public const string READ = 'legal_text:read';

    #[ApiProperty(identifier: false, required: true, schema: ['type' => 'string', 'enum' => ['mentions', 'privacy', 'cookies', 'terms', 'sales', 'dpa', 'source', 'accessibility', 'security']])]
    #[Groups([self::READ])]
    public string $page = '';

    /** The language this version is written in, which is not the one asked for when the page is missing in it. */
    #[ApiProperty(identifier: false, required: true, schema: ['type' => 'string', 'enum' => ['fr', 'en', 'ar']])]
    #[Groups([self::READ])]
    public string $language = '';

    /** Markdown. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $body = '';

    /** The version's date, as the page shows it. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'format' => 'date'])]
    #[Groups([self::READ])]
    public string $publishedOn = '';

    /** Whether someone with the right to publish said this version was checked; the page says it is a draft until then. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $validated = false;

    /**
     * The publisher's and host's identity the operator filled in, by the placeholder a text names it with
     * (`{{publisher.name}}`); one not filled in is absent, and the page shows « à compléter » in its place.
     *
     * An object even when empty (`preserve_empty_objects`), never a JSON list.
     *
     * @var \ArrayObject<string, string>
     */
    #[ApiProperty(required: true, schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public \ArrayObject $values;

    /** @param array<string, string> $values */
    public static function of(LegalText $text, array $values = []): self
    {
        $resource = new self();
        $resource->page = $text->getPage()->value;
        $resource->language = $text->getLanguage()->value;
        $resource->body = $text->getBody();
        $resource->publishedOn = $text->getPublishedOn()->format('Y-m-d');
        $resource->validated = $text->isValidated();
        $resource->values = new \ArrayObject($values);

        return $resource;
    }

    public static function pageOf(mixed $slug): ?LegalPage
    {
        return \is_string($slug) ? LegalPage::tryFrom($slug) : null;
    }

    public static function languageOf(mixed $code): ?LegalLanguage
    {
        return \is_string($code) ? LegalLanguage::tryFrom($code) : null;
    }
}
