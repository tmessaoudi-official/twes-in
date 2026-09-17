<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * That something changed, never what it now holds: a screen that hears it reads the record again through the API,
 * under its own permissions (docs/SPEC.md § 7, 2026-09-17). The kind is the audit trail's entity type.
 */
final readonly class LiveChange
{
    public function __construct(
        public string $kind,
        public ?Uuid $id,
        public string $action,
        public ?Uuid $actorUserId,
        public ?Uuid $companyId,
    ) {
    }
}
