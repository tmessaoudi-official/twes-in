<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * Why a number read part by part is refused: a part that does not fit, a rule between two parts, or the whole written
 * the other way round. `IdentifierRules` words it.
 */
final readonly class IdentifierPartFault
{
    private function __construct(
        public ?string $part,
        public ?IdentifierPartRule $rule = null,
    ) {
    }

    public static function part(string $part): self
    {
        return new self($part);
    }

    public static function rule(IdentifierPartRule $rule): self
    {
        return new self($rule->part, $rule);
    }

    public static function reversed(): self
    {
        return new self(null);
    }

    public function isReversed(): bool
    {
        return null === $this->part;
    }
}
