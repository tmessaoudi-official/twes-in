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
 * under its own permissions (docs/SPEC.md § 7, 2026-09-17). The kind is the audit trail's entity type. A change to what
 * is a person's own, whoever made it (their sessions, ended by an operator), names that person: it is said to them.
 * A change every signed-in person's screens depend on, as the platform's own settings are, is said to everyone, by kind
 * and action alone: no record, no actor, no company.
 */
final readonly class LiveChange
{
    public function __construct(
        public string $kind,
        public ?Uuid $id,
        public string $action,
        public ?Uuid $actorUserId,
        public ?Uuid $companyId,
        public ?Uuid $forUserId = null,
        public bool $toEveryone = false,
    ) {
    }

    public static function everyone(string $kind, string $action): self
    {
        return new self($kind, null, $action, null, null, toEveryone: true);
    }
}
