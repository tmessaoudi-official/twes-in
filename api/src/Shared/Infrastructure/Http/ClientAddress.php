<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The address a request came from, as the trusted proxies say it (framework.yaml): the key every per-client budget is
 * spent under, and what a shop's computer judges its own network by (`ShopNetwork`). Every request reaching the API
 * over HTTP has one; a caller with none (a console command, a request built by hand) is refused here rather than given
 * a key that every such caller would share, where one could spend the others' budget. `ClientAddressReadersTest` names
 * every reader.
 */
final class ClientAddress
{
    public static function of(?Request $request): string
    {
        $address = $request?->getClientIp();
        if (null === $address || '' === $address) {
            throw new \LogicException('No client address: a per-client budget is spent only by a request made over HTTP.');
        }

        return $address;
    }
}
