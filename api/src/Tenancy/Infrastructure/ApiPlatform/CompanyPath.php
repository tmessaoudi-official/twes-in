<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A path variable that must be a UUID. Anything else is 404 rather than 400: a malformed identifier and an
 * identifier that does not exist are the same non-answer, and neither confirms anything to a prober.
 *
 * @internal
 */
final readonly class CompanyPath
{
    /** @param array<string, mixed> $uriVariables */
    public static function identifier(array $uriVariables, string $name): Uuid
    {
        $raw = $uriVariables[$name] ?? null;
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new NotFoundHttpException('No such company.');
        }

        return Uuid::fromString($raw);
    }
}
