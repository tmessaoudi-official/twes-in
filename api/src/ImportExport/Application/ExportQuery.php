<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * What a list asked for, as its own query string says it: the words, the choices that narrow it and the order. The same
 * parameters the list's endpoint takes, so a screen hands over the address it is already showing.
 */
final readonly class ExportQuery
{
    /** @param array<array-key, mixed> $parameters */
    public function __construct(private array $parameters = [])
    {
    }

    /**
     * The query string itself, for a list whose filters take several values and intervals, which the list reads as one
     * (`ListFilters`).
     *
     * @return array<array-key, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function text(string $key = 'q'): ?string
    {
        $value = $this->parameters[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** @param list<string> $allowed */
    public function choice(string $key, array $allowed): ?string
    {
        $value = $this->parameters[$key] ?? null;

        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }

    public function flag(string $key): ?bool
    {
        return match ($this->parameters[$key] ?? null) {
            'true', '1', true => true,
            'false', '0', false => false,
            default => null,
        };
    }

    /**
     * The order the list shows, `order[name]=desc`, restricted to the columns it can be sorted by.
     *
     * @param list<string> $sorts
     *
     * @return array<string, 'asc'|'desc'>
     */
    public function order(array $sorts): array
    {
        $order = $this->parameters['order'] ?? null;
        if (!\is_array($order)) {
            return [];
        }
        $kept = [];
        foreach ($order as $column => $direction) {
            if (\in_array($column, $sorts, true) && \in_array($direction, ['asc', 'desc'], true)) {
                $kept[$column] = $direction;
            }
        }

        return $kept;
    }
}
