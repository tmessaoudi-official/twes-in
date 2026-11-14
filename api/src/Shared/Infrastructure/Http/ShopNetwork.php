<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * On a shop's own computer (APP_INSTALLATION=onprem) the platform's side, the operators' endpoints and sign-in and the
 * monitor's deep health, answers only from the shop's own network or its tailnet: once the public path is on, an
 * operator's stolen password would otherwise open from anywhere every company a support grant lets an operator read. A
 * server's operators sign in from anywhere, as before. The shop's network is Symfony's private ranges, which hold the
 * tailnet's (100.64.0.0/10, and its IPv6 addresses under fc00::/7) and no prefix a remote client can claim as its own.
 */
final readonly class ShopNetwork
{
    public function __construct(
        #[Autowire(param: 'app.installation')]
        private string $installation,
        private RequestStack $requests,
    ) {
    }

    /** Whether the request being served reaches a shop's computer from outside its network and tailnet. */
    public function outside(): bool
    {
        return 'onprem' === $this->installation
            && !IpUtils::checkIp(ClientAddress::of($this->requests->getMainRequest()), IpUtils::PRIVATE_RANGES);
    }
}
