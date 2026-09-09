<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use Symfony\Component\Uid\Uuid;

/** What a use case knows about an action worth recording; the trail adds when and from where. */
final readonly class AuditEntry
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public string $entityType,
        public ?Uuid $entityId,
        public string $action,
        public ?Uuid $actorUserId,
        public array $changes = [],
        public ?Uuid $companyId = null,
    ) {
    }
}
