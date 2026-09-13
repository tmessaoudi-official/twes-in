<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @internal the `{key}` path variable; the route's requirement already limits its characters */
final readonly class SettingKey
{
    /** @param array<string, mixed> $uriVariables */
    public static function of(array $uriVariables): string
    {
        $key = $uriVariables['key'] ?? null;

        return \is_string($key) ? $key : throw new NotFoundHttpException('No such setting.');
    }
}
