<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

use BcMath\Number;

/**
 * One setting as its module declares it in code: key, type, default, constraints, label key, module, and the
 * levels of its chain a value may be stored at. A definition refuses to exist with a default it would itself
 * refuse, so a declared default is always a valid value. A pattern definition covers a family of keys, such as
 * one layout per list screen.
 */
final readonly class SettingDefinition
{
    public const int TEXT_MAX_LENGTH = 4000;
    public const int JSON_MAX_BYTES = 32768;
    private const string KEY = '/^[a-z][a-z0-9_]*(\.[a-z0-9_<>-]+)+$/';
    private const string DECIMAL = '/^-?\d{1,15}(\.\d{1,6})?$/';
    private const string COLOUR = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @param list<SettingLevel> $overridableAt the levels a value may be stored at
     * @param list<string>       $choices       an enum's values
     * @param int|string|null    $min           inclusive; an int for an int, a decimal string for a decimal or money
     * @param int|string|null    $max           inclusive, as $min
     * @param string|null        $keyPattern    a regular expression, when the definition covers a family of keys
     *
     * @throws \InvalidArgumentException for a malformed key, a level outside the chain or a default it would refuse
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public SettingChain $chain,
        public array $overridableAt,
        public string $labelKey,
        public string $module,
        public array $choices = [],
        public int|string|null $min = null,
        public int|string|null $max = null,
        public ?int $maxLength = null,
        public ?string $keyPattern = null,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting key.', $key));
        }
        foreach ($overridableAt as $level) {
            if (!$chain->has($level)) {
                throw new \InvalidArgumentException(\sprintf('%s: the %s chain has no %s level.', $key, $chain->value, $level->value));
            }
        }
        if (SettingType::Enum === $type && [] === $choices) {
            throw new \InvalidArgumentException(\sprintf('%s: an enum declares its choices.', $key));
        }
        // A JSON setting may default to nothing: the screen that owns it knows its empty shape.
        if (null !== $default || SettingType::Json !== $type) {
            $refusal = $this->refusal($default);
            if (null !== $refusal) {
                throw new \InvalidArgumentException(\sprintf('%s: its default is refused, %s.', $key, $refusal));
            }
        }
    }

    public function isPattern(): bool
    {
        return null !== $this->keyPattern;
    }

    public function matches(string $key): bool
    {
        return null === $this->keyPattern ? $key === $this->key : 1 === preg_match($this->keyPattern, $key);
    }

    public function allows(SettingLevel $level): bool
    {
        return \in_array($level, $this->overridableAt, true);
    }

    /** @return string|null why the value cannot be stored, or null when it can */
    public function refusal(mixed $value): ?string
    {
        return match ($this->type) {
            SettingType::Bool => \is_bool($value) ? null : 'expected true or false',
            SettingType::Int => $this->intRefusal($value),
            SettingType::Decimal, SettingType::Money => $this->decimalRefusal($value),
            SettingType::Text => $this->textRefusal($value),
            SettingType::Enum => \is_string($value) && \in_array($value, $this->choices, true) ? null : 'expected one of '.implode(', ', $this->choices),
            SettingType::Colour => \is_string($value) && 1 === preg_match(self::COLOUR, $value) ? null : 'expected a colour written #rrggbb',
            SettingType::Json => $this->jsonRefusal($value),
        };
    }

    /** The one spelling a value is stored in: a colour in lower case. */
    public function normalize(mixed $value): mixed
    {
        return SettingType::Colour === $this->type && \is_string($value) ? strtolower($value) : $value;
    }

    private function intRefusal(mixed $value): ?string
    {
        if (!\is_int($value)) {
            return 'expected a whole number';
        }
        if (null !== $this->min && $value < (int) $this->min) {
            return 'expected at least '.$this->min;
        }
        if (null !== $this->max && $value > (int) $this->max) {
            return 'expected at most '.$this->max;
        }

        return null;
    }

    private function decimalRefusal(mixed $value): ?string
    {
        if (!\is_string($value) || !is_numeric($value) || 1 !== preg_match(self::DECIMAL, $value)) {
            return 'expected a decimal number written as text, with a dot';
        }
        $number = new Number($value);
        if (is_numeric($this->min) && $number < new Number($this->min)) {
            return 'expected at least '.$this->min;
        }
        if (is_numeric($this->max) && $number > new Number($this->max)) {
            return 'expected at most '.$this->max;
        }

        return null;
    }

    private function textRefusal(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return 'expected text';
        }
        $limit = $this->maxLength ?? self::TEXT_MAX_LENGTH;

        return mb_strlen($value) > $limit ? \sprintf('expected at most %d characters', $limit) : null;
    }

    private function jsonRefusal(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return 'expected a list or an object';
        }
        $encoded = json_encode($value);
        if (false === $encoded) {
            return 'expected a value JSON can represent';
        }

        return \strlen($encoded) > self::JSON_MAX_BYTES ? \sprintf('expected at most %d bytes once encoded', self::JSON_MAX_BYTES) : null;
    }
}
