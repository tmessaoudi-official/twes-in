<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * The first identifier rule some values break: the field it names, why in English, and the same reason as a stable
 * code with its parameters, which a screen translates (docs/SPEC.md § 7, 2026-09-19).
 */
final readonly class IdentifierRefusal
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public string $field,
        public string $message,
        public string $reason,
        public array $params = [],
    ) {
    }
}
