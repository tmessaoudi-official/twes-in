<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * What an entry's changes must not hold: a way to reach or name a person or a business. The journal names them by their
 * id; their address, phone, IBAN or name stays in the record, where erasure and a correction reach it, and a journal
 * row cannot be rewritten by the application. A key below is refused at any depth; the same word as a VALUE, in a
 * list of the fields that changed, names the field and is what the journal is for.
 */
final class NamesOnly
{
    /** The keys an entry never holds. */
    public const array HELD_BY_THE_RECORD = ['email', 'phone', 'iban', 'address', 'company', 'customer', 'vendor'];

    /**
     * The dotted path of every such key in the changes, never its value.
     *
     * @param array<array-key, mixed> $changes
     *
     * @return list<string>
     */
    public static function found(array $changes, string $under = ''): array
    {
        $found = [];
        foreach ($changes as $key => $value) {
            $path = '' === $under ? (string) $key : $under.'.'.$key;
            if (\is_string($key) && \in_array($key, self::HELD_BY_THE_RECORD, true)) {
                $found[] = $path;
            } elseif (\is_array($value)) {
                array_push($found, ...self::found($value, $path));
            }
        }

        return $found;
    }

    /**
     * The changes without any such key.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $changes
     *
     * @return array<K, mixed>
     */
    public static function without(array $changes): array
    {
        $kept = [];
        foreach ($changes as $key => $value) {
            if (\is_string($key) && \in_array($key, self::HELD_BY_THE_RECORD, true)) {
                continue;
            }
            $kept[$key] = \is_array($value) ? self::without($value) : $value;
        }

        return $kept;
    }
}
