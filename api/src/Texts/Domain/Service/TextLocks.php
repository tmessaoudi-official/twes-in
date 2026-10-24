<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\Service;

use App\Texts\Domain\ValueObject\TextAddress;

/**
 * The texts no override may change, as data (app.texts.locked): a whole domain (`*`, the fiscal texts) or keys of it,
 * exact or `prefix.*`. What is locked is what a mandatory mention or a legal status prints through; the law fixes it,
 * so no company and no operator words it otherwise.
 */
final readonly class TextLocks
{
    /**
     * @param array<string, array<int, string>> $locked the patterns by domain
     */
    public function __construct(private array $locked)
    {
    }

    public function locked(TextAddress $address): bool
    {
        return self::matches($this->locked[$address->domain] ?? [], $address->key);
    }

    /**
     * @param array<int, string> $patterns `*`, an exact key, or `prefix.*`
     */
    public static function matches(array $patterns, string $key): bool
    {
        foreach ($patterns as $pattern) {
            if ('*' === $pattern || $pattern === $key || (str_ends_with($pattern, '.*') && str_starts_with($key, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
