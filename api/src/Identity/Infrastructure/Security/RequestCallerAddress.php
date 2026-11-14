<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Login\CallerAddress;
use App\Shared\Infrastructure\Http\ClientAddress;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The main request's address: a sign-in, a step-up or a second-factor code is always answered over HTTP. An IPv6 one is
 * kept to its network, the 64 bits a provider hands one line, whose other 64 its machine picks at will: keyed whole,
 * one machine would be a new address at every guess.
 */
final readonly class RequestCallerAddress implements CallerAddress
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function key(): string
    {
        $address = ClientAddress::of($this->requests->getMainRequest());

        return str_contains($address, ':') ? IpUtils::anonymize($address, 0, 8) : $address;
    }
}
