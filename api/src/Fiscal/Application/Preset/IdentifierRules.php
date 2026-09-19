<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * What a preset asks of the registration numbers someone carries, a company or a business customer: only the
 * identifiers it knows, each with its shape and its check, and those it requires of that holder present.
 */
final class IdentifierRules
{
    public const string COMPANY = 'company';
    public const string BUSINESS_CUSTOMER = 'business_customer';

    private const array HOLDERS = [self::COMPANY => 'a company', self::BUSINESS_CUSTOMER => 'a business customer'];

    /**
     * The first rule the values break; null when they break none.
     *
     * @param array<string, string> $values registration numbers by identifier key, blank ones already left out
     * @param string                $holder one of the holder constants, or '' for someone the preset requires nothing of
     */
    public static function refusal(FiscalPreset $preset, array $values, string $holder): ?IdentifierRefusal
    {
        $known = [];
        foreach ($preset->identifiers as $identifier) {
            $known[] = $identifier->key;
            $field = "identifiers.$identifier->key";
            $value = $values[$identifier->key] ?? null;
            if (null === $value) {
                if ('' !== $holder && \in_array($holder, $identifier->requiredFor, true)) {
                    return new IdentifierRefusal($field, \sprintf('The %s preset requires %s to carry its %s.', $preset->country, self::HOLDERS[$holder] ?? $holder, $identifier->key), 'identifier_required', ['identifier' => $identifier->key]);
                }
                continue;
            }
            if (1 !== preg_match('#'.str_replace('#', '\#', $identifier->pattern).'#u', $value)) {
                return new IdentifierRefusal($field, \sprintf('This %s does not have the shape the %s preset expects.', $identifier->key, $preset->country), 'identifier_shape', ['identifier' => $identifier->key]);
            }
            if (null !== $identifier->check && !$identifier->check->accepts($value)) {
                return new IdentifierRefusal($field, \sprintf('This %s fails its check digits.', $identifier->key), 'identifier_checksum', ['identifier' => $identifier->key]);
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
