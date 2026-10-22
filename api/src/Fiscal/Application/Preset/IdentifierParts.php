<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * A registration number read part by part, as a preset writes it (`api/config/fiscal/<CC>.yaml`, `parts`): each part's
 * own pattern, the form it is stored in (`written`, `{number}{key}/{vat_code}/…`), and what one part asks of another.
 * Every writing a person uses (slashes, dots, hyphens, spaces, nothing at all) is read as the same number; one that is
 * not names the first part that does not fit, or says it was written the other way round, as a right-to-left
 * certificate reads.
 */
final readonly class IdentifierParts
{
    /** What a person writes between the parts, which the stored form writes its own way. */
    private const string SEPARATORS = '#[/.\-]#';

    /**
     * @param list<IdentifierPart>     $parts   in the order the number is written
     * @param string                   $written each part's name in braces, and what is written between them
     * @param list<IdentifierPartRule> $rules
     */
    public function __construct(
        public array $parts,
        public string $written,
        public array $rules = [],
    ) {
    }

    /** The one pattern the stored form matches, anchored at both ends. */
    public function pattern(): string
    {
        $patterns = [];
        foreach ($this->parts as $part) {
            $patterns[$part->name] = '(?:'.$part->pattern.')';
        }
        $pieces = preg_split('/(\{[a-z_]+\})/', $this->written, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];

        return '^'.implode('', array_map(static fn (string $piece): string => $patterns[trim($piece, '{}')] ?? preg_quote($piece, '#'), $pieces)).'$';
    }

    /** The number in its stored form; null when it cannot be read, which `fault()` then says why. */
    public function normalised(string $value): ?string
    {
        $values = $this->values($value);

        return null === $values || null !== $this->ruleBroken($values) ? null : $this->writtenOf($values);
    }

    /** Why the number cannot be read, or null when it can. */
    public function fault(string $value): ?IdentifierPartFault
    {
        $read = $this->read($value);
        if (\is_array($read)) {
            $broken = $this->ruleBroken($read);

            return null === $broken ? null : IdentifierPartFault::rule($broken);
        }
        if (null !== $this->reversed($value)) {
            return IdentifierPartFault::reversed();
        }

        return IdentifierPartFault::part($read);
    }

    /** @return array<string, string>|null each part's value; null when a part does not fit */
    private function values(string $value): ?array
    {
        $read = $this->read($value);

        return \is_array($read) ? $read : null;
    }

    /**
     * Each part's value, or the name of the first part that does not fit. Written with separators where the stored
     * form has them, each piece is read on its own, so a part left out is named rather than its neighbour.
     *
     * @return array<string, string>|string
     */
    private function read(string $value): array|string
    {
        return $this->readIn($value, $this->segments());
    }

    /**
     * @param list<list<IdentifierPart>> $segments
     *
     * @return array<string, string>|string
     */
    private function readIn(string $value, array $segments): array|string
    {
        $pieces = preg_split(self::SEPARATORS, $value) ?: [];
        if (\count($segments) > 1 && \count($pieces) === \count($segments)) {
            $values = [];
            foreach ($segments as $i => $segment) {
                $read = $this->consumed($pieces[$i], $segment);
                if (\is_string($read)) {
                    return $read;
                }
                $values += $read;
            }

            return $values;
        }

        return $this->consumed((string) preg_replace(self::SEPARATORS, '', $value), array_merge(...$segments));
    }

    /**
     * The parts read from the start of the text, in order, the whole text taken.
     *
     * @param list<IdentifierPart> $parts
     *
     * @return array<string, string>|string
     */
    private function consumed(string $text, array $parts): array|string
    {
        $values = [];
        $at = 0;
        $last = '';
        foreach ($parts as $part) {
            $last = $part->name;
            if (1 !== preg_match('#\G(?:'.$part->pattern.')#u', $text, $match, 0, $at)) {
                return $part->name;
            }
            $values[$part->name] = $match[0];
            $at += \strlen($match[0]);
        }
        if ($at !== \strlen($text)) {
            // Something more after the last part: the last part is written wrong.
            return $last;
        }

        return $values;
    }

    /**
     * The parts read in the certificate's right-to-left order: the stored form's groups taken last first, each group
     * as written or itself turned round (« 000/M/A/A1234567 »).
     *
     * @return array<string, string>|null
     */
    private function reversed(string $value): ?array
    {
        $segments = array_reverse($this->segments());
        if (\count($segments) < 2) {
            return null;
        }
        foreach ([$segments, array_map(array_reverse(...), $segments)] as $order) {
            $read = $this->readIn($value, $order);
            if (\is_array($read)) {
                return $read;
            }
        }

        return null;
    }

    /** @param array<string, string> $values */
    private function ruleBroken(array $values): ?IdentifierPartRule
    {
        foreach ($this->rules as $rule) {
            if (1 === preg_match('#^(?:'.$rule->unlessPattern.')$#u', $values[$rule->unlessPart] ?? '')) {
                continue;
            }
            if (1 !== preg_match('#^(?:'.$rule->pattern.')$#u', $values[$rule->part] ?? '')) {
                return $rule;
            }
        }

        return null;
    }

    /** @param array<string, string> $values */
    private function writtenOf(array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $name): string => $values[$name[1]] ?? '', $this->written);
    }

    /**
     * The parts grouped as the stored form writes them between its separators.
     *
     * @return list<list<IdentifierPart>>
     */
    private function segments(): array
    {
        $byName = [];
        foreach ($this->parts as $part) {
            $byName[$part->name] = $part;
        }
        $segments = [];
        foreach (preg_split(self::SEPARATORS, $this->written) ?: [] as $group) {
            preg_match_all('/\{([a-z_]+)\}/', $group, $names);
            $segment = array_values(array_filter(array_map(static fn (string $name): ?IdentifierPart => $byName[$name] ?? null, $names[1])));
            if ([] !== $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }
}
