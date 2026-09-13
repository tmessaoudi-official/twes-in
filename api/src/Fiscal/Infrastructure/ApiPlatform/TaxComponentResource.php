<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's own tax components (docs/SPEC.md § 3 Fiscal presets). The code and the family are chosen once, at
 * creation; everything else is revised with a PUT. The shape is checked here, the fiscal rules by the entity.
 */
#[ApiResource(
    shortName: 'TaxComponent',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/tax-components',
            provider: TaxComponentCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/tax-components',
            processor: CreateTaxComponentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::CREATE, self::WRITE]],
            validationContext: ['groups' => [self::CREATE, self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/tax-components/{componentId}',
            processor: ReviseTaxComponentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE, self::REVISE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class TaxComponentResource
{
    public const string READ = 'tax_component:read';
    public const string WRITE = 'tax_component:write';
    public const string CREATE = 'tax_component:create';
    public const string REVISE = 'tax_component:revise';

    // Not an API Platform identifier: every route is an explicit uriTemplate, and an identifier would publish an
    // item route no provider answers.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Regex(pattern: TaxComponent::CODE, groups: [self::CREATE])]
    #[Groups([self::READ, self::CREATE])]
    public string $code = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [TaxKind::PercentageLine->value, TaxKind::FixedDocument->value, TaxKind::WithholdingTotal->value]])]
    #[Groups([self::READ])]
    public ?string $kind = null;

    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Choice(callback: [self::class, 'families'], groups: [self::CREATE])]
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']])]
    #[Groups([self::READ, self::CREATE])]
    public string $family = '';

    /** A percentage, "19" or "5.5", for the percentage and withholding kinds. */
    #[Groups([self::READ, self::WRITE])]
    public ?string $rate = null;

    /** In the company's currency, for a fixed charge. */
    #[Groups([self::READ, self::WRITE])]
    public ?string $amount = null;

    /** In the company's currency, for a withholding: it applies from this total on. */
    #[Groups([self::READ, self::WRITE])]
    public ?string $threshold = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $entersVatBase = false;

    #[Groups([self::READ, self::WRITE])]
    public bool $isDefault = false;

    /** An inactive tax stays on the documents that carry it and is no longer offered for new ones. */
    #[Groups([self::READ, self::REVISE])]
    public bool $isActive = true;

    #[Assert\Length(max: 500, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $exemptionMention = null;

    #[Assert\PositiveOrZero(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $sortOrder = 0;

    /** @return list<string> */
    public static function families(): array
    {
        return array_map(static fn (TaxFamily $family) => $family->value, TaxFamily::cases());
    }

    public static function of(TaxComponent $component): self
    {
        $resource = new self();
        $resource->id = $component->getId()->toRfc4122();
        $resource->code = $component->getCode();
        $resource->name = $component->getName();
        $resource->kind = $component->getKind()->value;
        $resource->family = $component->getFamily()->value;
        $resource->rate = $component->getRate();
        $resource->amount = $component->getAmount();
        $resource->threshold = $component->getThreshold();
        $resource->entersVatBase = $component->entersVatBase();
        $resource->isDefault = $component->isDefault();
        $resource->isActive = $component->isActive();
        $resource->exemptionMention = $component->getExemptionMention();
        $resource->sortOrder = $component->getSortOrder();

        return $resource;
    }
}
