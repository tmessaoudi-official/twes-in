<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * What a preset asks of the registration numbers someone carries, a company or a business customer: only the
 * identifiers it knows, each with its shape and its check, and those it requires of that holder present. Anyone but
 * the company may hold another state's number where the preset lists that state's pattern.
 */
final class IdentifierRules
{
    public const string COMPANY = 'company';
    public const string BUSINESS_CUSTOMER = 'business_customer';

    private const array HOLDERS = [self::COMPANY => 'a company', self::BUSINESS_CUSTOMER => 'a business customer'];

    /**
     * The keys of the numbers the preset requires of a holder, in the preset's order: the one reading of `required_for`.
     *
     * @return list<string>
     */
    public static function requiredOf(FiscalPreset $preset, string $holder): array
    {
        $keys = [];
        foreach ($preset->identifiers as $identifier) {
            if ($identifier->isRequiredOf($holder)) {
                $keys[] = $identifier->key;
            }
        }

        return $keys;
    }

    /**
     * The numbers in the form the preset stores them in: one read part by part is written its one way, whichever way it
     * was typed (`1234567aam000`, `1234567/A/A/M/000`, `1234567A/A/M/000` are one matricule); any other is kept as it
     * came, for `refusal()` to judge.
     *
     * @param array<string, string> $values registration numbers by identifier key, as `RegistrationNumbers::kept()` gives them
     * @param string                $holder one of the holder constants, or ''
     *
     * @return array<string, string>
     */
    public static function normalised(FiscalPreset $preset, array $values, string $holder): array
    {
        foreach ($preset->identifiers as $identifier) {
            $value = $values[$identifier->key] ?? null;
            if (null === $value || null === $identifier->parts || null !== $identifier->foreignPatternOf($value, $holder)) {
                continue;
            }
            $values[$identifier->key] = $identifier->parts->normalised($value) ?? $value;
        }

        return $values;
    }

    /**
     * The first rule the values break; null when they break none.
     *
     * @param array<string, string> $values  registration numbers by identifier key, blank ones already left out
     * @param string                $holder  one of the holder constants, or '' for someone the preset requires nothing of
     * @param list<string>          $excused required keys the holder may go on lacking, as a record that already did
     */
    public static function refusal(FiscalPreset $preset, array $values, string $holder, array $excused = []): ?IdentifierRefusal
    {
        $known = [];
        foreach ($preset->identifiers as $identifier) {
            $known[] = $identifier->key;
            $field = "identifiers.$identifier->key";
            $value = $values[$identifier->key] ?? null;
            // Every refusal of a number shows how it is written, as the preset's example.
            $example = ['identifier' => $identifier->key, 'example' => $identifier->example];
            if (null === $value) {
                if ($identifier->isRequiredOf($holder) && !\in_array($identifier->key, $excused, true)) {
                    return new IdentifierRefusal($field, \sprintf('The %s preset requires %s to carry its %s.', $preset->country, self::HOLDERS[$holder] ?? $holder, $identifier->key), 'identifier_required', $example);
                }
                continue;
            }
            // Another state's number has that state's shape; the preset's check digits are its own country's alone.
            $foreign = $identifier->foreignPatternOf($value, $holder);
            $fault = null === $foreign ? $identifier->parts?->fault($value) : null;
            if (null !== $fault && $fault->isReversed()) {
                return new IdentifierRefusal($field, \sprintf('This %s is written the other way round, as a right-to-left certificate reads.', $identifier->key), 'identifier_reversed', $example);
            }
            if (null !== $fault && null !== $fault->rule) {
                return new IdentifierRefusal($field, \sprintf('The %s part of this %s does not go with its other parts.', $fault->part, $identifier->key), 'identifier_part_rule', [...$example, 'part' => (string) $fault->part, 'rule' => $fault->rule->name]);
            }
            if (null !== $fault) {
                return new IdentifierRefusal($field, \sprintf('The %s part of this %s is not written as it should be.', $fault->part, $identifier->key), 'identifier_part', [...$example, 'part' => (string) $fault->part]);
            }
            if (1 !== preg_match('#'.str_replace('#', '\#', $foreign ?? $identifier->pattern).'#u', $value)) {
                return new IdentifierRefusal($field, \sprintf('This %s does not have the shape the %s preset expects.', $identifier->key, $preset->country), 'identifier_shape', $example);
            }
            if (null === $foreign && null !== $identifier->check && !$identifier->check->accepts($value)) {
                return new IdentifierRefusal($field, \sprintf('This %s fails its check digits.', $identifier->key), 'identifier_checksum', $example);
            }
        }
        foreach (array_keys($values) as $key) {
            if (!\in_array($key, $known, true)) {
                return new IdentifierRefusal("identifiers.$key", \sprintf('The %s preset knows no identifier "%s".', $preset->country, $key), 'unknown_identifier', ['identifier' => $key]);
            }
        }

        return null;
    }
}
