<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The path as the router matches it. `getPathInfo()` is the raw path, still percent-encoded, while the router and the
 * firewall's `access_control` decode it once first: a listener deciding on the raw value lets `/%61pi/...` or one escaped
 * digit of a company id slip past it to the very route it guards. Decoded once, exactly as the router does, so `%2561`
 * stays `%61` there as here.
 */
final class RoutedPath
{
    public static function of(Request $request): string
    {
        return rawurldecode($request->getPathInfo());
    }
}
