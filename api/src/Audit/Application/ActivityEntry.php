<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use Symfony\Component\Uid\Uuid;

/**
 * One line of the journal as it is read: who, what, on which record, when, and the names of what changed. A value is
 * never part of it (docs/SPEC.md § 7, audit names only).
 */
final readonly class ActivityEntry
{
    /** @param list<string> $fields */
    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $at,
        public string $action,
        public string $entityType,
        public ?Uuid $entityId,
        public ?Uuid $actorId,
        public ?string $actorName,
        public array $fields,
        public ?string $ip,
    ) {
    }

    /**
     * The names of what an entry changed: the `fields` a use case listed, else the keys of what it recorded. Some older
     * use cases recorded values beside the names (a tax's rate, an attempted address); only the names leave here.
     *
     * @param array<array-key, mixed> $changes
     *
     * @return list<string>
     */
    public static function fieldsOf(array $changes): array
    {
        $listed = $changes['fields'] ?? null;
        if (\is_array($listed) && array_is_list($listed)) {
            return array_values(array_filter($listed, is_string(...)));
        }

        return array_values(array_filter(array_keys($changes), is_string(...)));
    }
}
