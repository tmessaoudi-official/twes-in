<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Audit\Application\ActivityEntry;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * The company's « Journal d'activité » (docs/SPEC.md § 7, 2026-09-26 23:04), read with audit.read: who did what to
 * which record and when, newest first, within what the company keeps. What changed is named, never its value; the
 * address it was done from only for a reader who manages the team.
 */
#[ApiResource(
    shortName: 'Activity',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/activity',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: ActivityCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the action, the kind of record or the name of who did it.'),
                'actorId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'What these people did, OR\'d; a single `actorId=…` still works: a member\'s « Activité ».', constraints: []),
                'entityType[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => self::WORD]], description: 'Several kinds of record, OR\'d: `entityType[]=invoice&entityType[]=customer`.', constraints: []),
                'entityId' => new QueryParameter(schema: self::ID, description: 'What was done to this one record: its « Historique ».'),
                'action[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => self::ACTION]], description: 'Several actions, OR\'d: `action[]=invoice.issued`.', constraints: []),
                'at[from]' => new QueryParameter(schema: self::DAY, description: 'On or after this day, in the company\'s own calendar; never before what the company keeps.'),
                'at[to]' => new QueryParameter(schema: self::DAY, description: 'On or before this day, in the company\'s own calendar.'),
                'order[at]' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']], description: 'Newest first unless asked otherwise.'),
            ],
        ),
    ],
)]
final class ActivityResource
{
    public const string READ = 'activity:read';
    public const string WORD = '^[a-z][a-z0-9_]{0,63}$';
    public const string ACTION = '^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$';
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    private const array DAY = ['type' => 'string', 'format' => 'date'];

    // No identifier: reached only through the list, and an entry is not addressable by itself.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    /** The moment, in UTC. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $at = '';

    /** What was done, as `<kind>.<verb>`: `invoice.issued`, `auth.login`. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $action = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $entityType = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $entityId = null;

    /** Null when nobody was signed in, or the platform did it. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $actorId = null;

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $actorName = null;

    /** @var list<string> the names of what changed, never their values */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public array $fields = [];

    /** Where it was done from: only for a reader who manages the team, null for anyone else. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $ip = null;

    public static function of(ActivityEntry $entry, bool $withAddress): self
    {
        $resource = new self();
        $resource->id = $entry->id->toRfc4122();
        $resource->at = $entry->at->format(\DATE_ATOM);
        $resource->action = $entry->action;
        $resource->entityType = $entry->entityType;
        $resource->entityId = $entry->entityId?->toRfc4122();
        $resource->actorId = $entry->actorId?->toRfc4122();
        $resource->actorName = $entry->actorName;
        $resource->fields = $entry->fields;
        $resource->ip = $withAddress ? $entry->ip : null;

        return $resource;
    }
}
