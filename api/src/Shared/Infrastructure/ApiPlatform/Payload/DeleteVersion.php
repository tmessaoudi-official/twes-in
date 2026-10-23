<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Payload;

use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Component\HttpFoundation\Request;

/**
 * The version a delete of an edited record names, as `?version=` on its address: a DELETE carries no body, and a
 * record saved since the screen read it is refused (409) rather than going unseen (tests/Architecture/RecordVersionsTest).
 * The operation declares it as a required QueryParameter of `SCHEMA`, so its OpenAPI document says so; its processor
 * reads it with `of()`, which refuses a missing or malformed one by the reasons the screens already read.
 */
final class DeleteVersion
{
    /**
     * What the operation's `version` QueryParameter declares, beside `required: true` and `constraints: []` (the
     * processor refuses through of()): an attribute takes no call, so the declaration is written at each DELETE.
     */
    public const array SCHEMA = ['type' => 'integer', 'minimum' => 1];

    /** @param array<string, mixed> $context the processor's own */
    public static function of(array $context): int
    {
        $request = $context['request'] ?? null;
        $read = $request instanceof Request ? $request->query->get('version') : null;
        if (null === $read || '' === $read) {
            throw DomainRule::refused('version', 'The version the record was read at is required.', 'value_required');
        }
        $version = filter_var($read, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!\is_int($version)) {
            throw DomainRule::refused('version', 'The version is a whole number from 1.', 'invalid_value');
        }

        return $version;
    }
}
