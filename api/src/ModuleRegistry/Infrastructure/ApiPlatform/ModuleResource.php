<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\ModuleRegistry\Application\ModuleView;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The modules a company can have and whether it has each on (docs/SPEC.md § 3 Modules). Read with company.read,
 * switched with company.settings. A module another enabled module needs stays on, and one goes on only after what it
 * needs, both refused with 409 naming the modules. Switched off, a module's resources answer 404 and its data is kept.
 */
#[ApiResource(
    shortName: 'Module',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/modules',
            provider: ModuleCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/modules/{moduleKey}',
            processor: SwitchModuleProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        // « Me prévenir » on a planned module (docs/SPEC.md § 7, 2026-09-26 10:08): 409 on one that already ships.
        new Put(
            uriTemplate: '/companies/{companyId}/modules/{moduleKey}/interest',
            processor: ModuleInterestProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::INTEREST]],
            validationContext: ['groups' => [self::INTEREST]],
        ),
    ],
)]
final class ModuleResource
{
    public const string READ = 'module:read';
    public const string WRITE = 'module:write';
    public const string INTEREST = 'module:interest';

    #[ApiProperty(identifier: false, writable: false, required: true)]
    #[Groups([self::READ])]
    public string $key = '';

    /** The translation key of the module's name. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $labelKey = '';

    /** @var list<string> the keys of the modules it needs on */
    #[ApiProperty(writable: false, required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $dependencies = [];

    /** @var list<string> the permissions its resources check */
    #[ApiProperty(writable: false, required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $permissions = [];

    /**
     * The version a module not built yet is expected in (docs/SPEC.md § 7, 2026-09-26 10:08); null once it is real. A
     * planned module is never on, and switching it answers 409. Absent from a real module's row.
     */
    #[ApiProperty(writable: false, required: false, schema: ['type' => 'string', 'enum' => ['v1', 'later']])]
    #[Groups([self::READ])]
    public ?string $planned = null;

    /**
     * Whether the company asked to be told when this planned module arrives (« Me prévenir »). Absent from a real
     * module's row.
     */
    #[ApiProperty(required: false)]
    #[Assert\NotNull(groups: [self::INTEREST])]
    #[Groups([self::READ, self::INTEREST])]
    public ?bool $interested = null;

    #[ApiProperty(required: true)]
    #[Assert\NotNull(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?bool $enabled = null;

    /** @param bool $interested whether the company waits for it: read on a planned module only */
    public static function of(ModuleView $view, bool $interested = false): self
    {
        $resource = new self();
        $resource->key = $view->manifest->key;
        $resource->labelKey = $view->manifest->labelKey;
        $resource->dependencies = $view->manifest->dependencies;
        $resource->permissions = $view->manifest->permissions;
        $resource->planned = $view->manifest->planned;
        $resource->interested = null === $view->manifest->planned ? null : $interested;
        $resource->enabled = $view->enabled;

        return $resource;
    }
}
