<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain\ValueObject;

use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Refusal;
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
    private const string REASON = '/^[a-z]+(_[a-z]+)+$/';

    /**
     * @param list<SettingLevel> $overridableAt the levels a value may be stored at
     * @param list<string>       $choices       an enum's values
     * @param int|string|null    $min           inclusive; an int for an int, a decimal string for a decimal or money
     * @param int|string|null    $max           inclusive, as $min
     * @param string|null        $pattern       a regular expression a text value must match
     * @param string|null        $keyPattern    a regular expression, when the definition covers a family of keys
     * @param string|null        $patternReason the reason a value outside the pattern is refused with, whose text says
     *                                          the format with an example; required beside a pattern
     * @param bool               $phone         a phone number, which the change keeps as its country's numbering plan
     *                                          does (E.164) and refuses with that plan's reasons; with no country to
     *                                          read it by, it is written with « + » or « 00 » first
     *
     * @throws \InvalidArgumentException for a malformed key, a level outside the chain, a pattern without its reason or a
     *                                   default it would refuse
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
        public ?string $pattern = null,
        public ?string $keyPattern = null,
        public ?string $patternReason = null,
        public bool $phone = false,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting key.', $key));
        }
        // A value refused by its shape alone would be told only « refusé »: the pattern names the sentence that says it.
        if (null !== $pattern && (null === $patternReason || 1 !== preg_match(self::REASON, $patternReason))) {
            throw new \InvalidArgumentException(\sprintf('%s: a pattern declares the reason it refuses a value with.', $key));
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
                throw new \InvalidArgumentException(\sprintf('%s: its default is refused, %s.', $key, $refusal->message));
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

    /**
     * Why the value cannot be stored, or null when it can: the reason a screen says it in, that text's parameters and
     * an English message beside them.
     */
    public function refusal(mixed $value): ?Refusal
    {
        $value = $this->typed($value);

        return match ($this->type) {
            SettingType::Bool => \is_bool($value) ? null : self::notYesOrNo(),
            SettingType::Int => $this->intRefusal($value),
            SettingType::Decimal, SettingType::Money => $this->decimalRefusal($value),
            SettingType::Text => $this->textRefusal($value),
            SettingType::Enum => \is_string($value) && \in_array($value, $this->choices, true) ? null : new Refusal('unknown_choice', 'expected one of '.implode(', ', $this->choices)),
            SettingType::Colour => \is_string($value) && 1 === preg_match(self::COLOUR, $value) ? null : new Refusal('not_a_colour', 'expected a colour written #rrggbb'),
            SettingType::Json => $this->jsonRefusal($value),
        };
    }

    /**
     * Every refusal this setting can answer, each with the parameters its text is given: what the screens' texts are
     * held to, so no refusal reaches a field as a bare key.
     *
     * @return list<Refusal>
     */
    public function refusals(): array
    {
        return match ($this->type) {
            SettingType::Bool => [self::notYesOrNo()],
            SettingType::Int => [self::notAWholeNumber(), ...(null === $this->min && null === $this->max ? [] : [$this->outOfBounds(true)])],
            SettingType::Decimal, SettingType::Money => [self::notANumber(), ...(is_numeric($this->min) || is_numeric($this->max) ? [$this->outOfBounds(false)] : [])],
            SettingType::Text => [self::notText(), $this->tooLong(), ...(null === $this->pattern ? [] : [$this->outsidePattern()]), ...($this->phone ? self::phoneRefusals() : [])],
            SettingType::Enum => [new Refusal('unknown_choice', 'expected one of '.implode(', ', $this->choices))],
            SettingType::Colour => [new Refusal('not_a_colour', 'expected a colour written #rrggbb')],
            SettingType::Json => [new Refusal('invalid_value', 'expected a list or an object')],
        };
    }

    /** The one spelling a value is stored in: what was typed without its ends, a colour in lower case. */
    public function normalize(mixed $value): mixed
    {
        $value = $this->typed($value);

        return SettingType::Colour === $this->type && \is_string($value) ? strtolower($value) : $value;
    }

    /**
     * A value a person types (a text, a colour, a number written as text) without the spaces and invisible marks a
     * paste brings at its ends, which nobody sees and nobody meant; a choice is picked from a list, never typed.
     */
    private function typed(mixed $value): mixed
    {
        $typed = match ($this->type) {
            SettingType::Text, SettingType::Colour, SettingType::Decimal, SettingType::Money => true,
            default => false,
        };

        return $typed && \is_string($value) ? TypedText::trimmed($value) : $value;
    }

    private function intRefusal(mixed $value): ?Refusal
    {
        if (!\is_int($value)) {
            return self::notAWholeNumber();
        }
        if ((null !== $this->min && $value < (int) $this->min) || (null !== $this->max && $value > (int) $this->max)) {
            return $this->outOfBounds(true);
        }

        return null;
    }

    private function decimalRefusal(mixed $value): ?Refusal
    {
        if (!\is_string($value) || !is_numeric($value) || 1 !== preg_match(self::DECIMAL, $value)) {
            return self::notANumber();
        }
        $number = new Number($value);
        if ((is_numeric($this->min) && $number < new Number($this->min)) || (is_numeric($this->max) && $number > new Number($this->max))) {
            return $this->outOfBounds(false);
        }

        return null;
    }

    private function textRefusal(mixed $value): ?Refusal
    {
        if (!\is_string($value)) {
            return self::notText();
        }
        if (mb_strlen($value) > ($this->maxLength ?? self::TEXT_MAX_LENGTH)) {
            return $this->tooLong();
        }

        return null === $this->pattern || 1 === preg_match($this->pattern, $value) ? null : $this->outsidePattern();
    }

    /** @return list<Refusal> what the numbering plans refuse a phone with, as the change says it */
    private static function phoneRefusals(): array
    {
        return [
            new Refusal('phone_length', 'too many or too few digits for its country', ['example' => '+216 30 010 123', 'callingCode' => '+216']),
            new Refusal('phone_outside_ranges', 'not a number its country hands out', ['example' => '+216 30 010 123', 'callingCode' => '+216']),
            new Refusal('phone_country', 'a phone with no country to read it by starts with + and its country code'),
            new Refusal('invalid_phone', 'expected a phone number'),
        ];
    }

    private function jsonRefusal(mixed $value): ?Refusal
    {
        if (!\is_array($value)) {
            return new Refusal('invalid_value', 'expected a list or an object');
        }
        $encoded = json_encode($value);
        if (false === $encoded) {
            return new Refusal('invalid_value', 'expected a value JSON can represent');
        }

        return \strlen($encoded) > self::JSON_MAX_BYTES ? new Refusal('invalid_value', \sprintf('expected at most %d bytes once encoded', self::JSON_MAX_BYTES)) : null;
    }

    /**
     * A number outside its bounds is told both where it has both, so the person reads the whole range at once; a whole
     * number's range is said as such.
     */
    private function outOfBounds(bool $whole): Refusal
    {
        $min = null === $this->min ? null : ($whole ? (int) $this->min : (string) $this->min);
        $max = null === $this->max ? null : ($whole ? (int) $this->max : (string) $this->max);
        if (null !== $min && null !== $max) {
            return new Refusal($whole ? 'whole_number_between' : 'number_between', \sprintf('expected from %s to %s', $min, $max), ['min' => $min, 'max' => $max]);
        }

        if (null !== $min) {
            return new Refusal('at_least', 'expected at least '.$min, ['min' => $min]);
        }

        return new Refusal('at_most', 'expected at most '.$max, null === $max ? [] : ['max' => $max]);
    }

    private function tooLong(): Refusal
    {
        $limit = $this->maxLength ?? self::TEXT_MAX_LENGTH;

        return new Refusal('invalid_text', \sprintf('expected at most %d characters', $limit), ['max' => $limit]);
    }

    private function outsidePattern(): Refusal
    {
        return new Refusal($this->patternReason ?? 'invalid_value', 'expected text matching '.$this->pattern);
    }

    private static function notYesOrNo(): Refusal
    {
        return new Refusal('not_yes_or_no', 'expected true or false');
    }

    private static function notAWholeNumber(): Refusal
    {
        return new Refusal('not_a_whole_number', 'expected a whole number');
    }

    private static function notANumber(): Refusal
    {
        return new Refusal('not_a_number', 'expected a decimal number written as text, with a dot');
    }

    private static function notText(): Refusal
    {
        return new Refusal('invalid_value', 'expected text');
    }
}
