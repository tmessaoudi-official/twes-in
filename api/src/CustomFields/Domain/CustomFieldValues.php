<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

/**
 * A record's custom field values checked against its company's fields. A key no field declares is refused, a required
 * field must hold something, and each value must be of its field's type. A retired field is no longer asked for: what
 * the record stored stays and whatever is sent for it is ignored, so a form opened before it was retired still saves.
 * A value resent exactly as stored is kept even if its field has since withdrawn that choice.
 */
final class CustomFieldValues
{
    public const int TEXT_MAX = 2000;

    /**
     * @param list<CustomFieldRule>                $rules
     * @param array<array-key, mixed>              $submitted
     * @param array<string, string|int|float|bool> $stored
     *
     * @return array<string, string|int|float|bool>
     *
     * @throws InvalidCustomFieldValue
     */
    public static function checked(array $rules, array $submitted, array $stored): array
    {
        $byKey = [];
        foreach ($rules as $rule) {
            $byKey[$rule->key] = $rule;
        }
        foreach (array_keys($submitted) as $key) {
            if (!isset($byKey[(string) $key])) {
                throw new InvalidCustomFieldValue('customFields.'.$key, \sprintf('No custom field "%s" is declared.', $key));
            }
        }

        $values = [];
        foreach ($rules as $rule) {
            $key = $rule->key;
            if (!$rule->active) {
                if (\array_key_exists($key, $stored)) {
                    $values[$key] = $stored[$key];
                }
                continue;
            }

            $value = $submitted[$key] ?? null;
            if (\is_string($value)) {
                $value = trim($value);
            }
            if (null === $value || '' === $value) {
                if ($rule->required) {
                    throw new InvalidCustomFieldValue('customFields.'.$key, 'This field is required.');
                }
                continue;
            }
            $values[$key] = \array_key_exists($key, $stored) && $stored[$key] === $value ? $stored[$key] : self::check($rule, $value);
        }

        return $values;
    }

    /** @throws InvalidCustomFieldValue */
    private static function check(CustomFieldRule $rule, mixed $value): string|int|float|bool
    {
        return match ($rule->type) {
            CustomFieldType::Text => \is_string($value) && mb_strlen($value) <= self::TEXT_MAX ? $value : self::refuse($rule, \sprintf('Text of at most %d characters.', self::TEXT_MAX)),
            CustomFieldType::Number => \is_int($value) || (\is_float($value) && is_finite($value)) ? $value : self::refuse($rule, 'A number.'),
            CustomFieldType::Date => \is_string($value) && self::isDate($value) ? $value : self::refuse($rule, 'A date written YYYY-MM-DD.'),
            CustomFieldType::Bool => \is_bool($value) ? $value : self::refuse($rule, 'True or false.'),
            CustomFieldType::Choice => \is_string($value) && \in_array($value, $rule->choices, true) ? $value : self::refuse($rule, \sprintf('One of: %s.', implode(', ', $rule->choices))),
        };
    }

    /** @throws InvalidCustomFieldValue */
    private static function refuse(CustomFieldRule $rule, string $message): never
    {
        throw new InvalidCustomFieldValue('customFields.'.$rule->key, $message);
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }
}
