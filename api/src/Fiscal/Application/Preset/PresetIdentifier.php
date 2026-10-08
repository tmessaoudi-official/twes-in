<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Fiscal\Domain\IdentifierCheck;

/** A registration number a country's documents carry: the shape it has and, when its research sources one, its check. */
final readonly class PresetIdentifier
{
    /**
     * @param string                $pattern         a regular expression the whole value matches, without delimiters
     * @param list<string>          $requiredFor     who must carry it: company, business_customer
     * @param array<string, string> $foreignPatterns the same number as other states issue it, by the prefix it starts
     *                                               with: a customer or a supplier abroad holds its own country's, the
     *                                               company never does; each pattern anchored at both ends
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $pattern,
        public array $requiredFor,
        public ?IdentifierCheck $check = null,
        public array $foreignPatterns = [],
    ) {
    }

    /**
     * Another state's pattern when the value carries that state's prefix and its holder may hold one; null when the
     * preset's own pattern and check apply.
     *
     * @param string $holder one of the IdentifierRules holder constants, or ''
     */
    public function foreignPatternOf(string $value, string $holder): ?string
    {
        return IdentifierRules::COMPANY === $holder ? null : $this->foreignPatterns[substr($value, 0, 2)] ?? null;
    }

    /**
     * The one pattern a form checks a holder's value against before the API does: the preset's own, or any of the
     * states' a value may come from. The check digits stay the API's.
     *
     * @param string $holder one of the IdentifierRules holder constants, or ''
     */
    public function patternFor(string $holder): string
    {
        if (IdentifierRules::COMPANY === $holder || [] === $this->foreignPatterns) {
            return $this->pattern;
        }
        $bodies = array_map(static fn (string $pattern): string => substr($pattern, 1, -1), [$this->pattern, ...array_values($this->foreignPatterns)]);

        return '^(?:'.implode('|', $bodies).')$';
    }
}
