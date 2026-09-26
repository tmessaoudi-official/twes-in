<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;

/**
 * A module of the complete product not built yet (docs/SPEC.md § 7, 2026-09-26 10:08): the menus show it « Bientôt »
 * from the catalogue, never from a list of their own.
 */
final readonly class MePlannedModule
{
    public function __construct(
        #[ApiProperty(required: true)] public string $key,
        #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['v1', 'later']])] public string $planned,
    ) {
    }
}
