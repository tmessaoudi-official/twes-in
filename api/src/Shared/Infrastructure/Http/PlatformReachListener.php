<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The platform's endpoints and the deep health, reached on a shop's computer from outside its network (`ShopNetwork`),
 * are not there: 404, as a path that does not exist, before the firewall reads a session, so a stolen session cookie
 * finds nothing either, nor does a percent-escaped spelling of the path, read as the router reads it (`RoutedPath`).
 * The operators' sign-in is refused by `UserChecker`, on every way in.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final readonly class PlatformReachListener
{
    public const string PATHS = '#^/api/(platform(/|$)|health/deep$)#';

    public function __construct(private ShopNetwork $network)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() && 1 === preg_match(self::PATHS, RoutedPath::of($event->getRequest())) && $this->network->outside()) {
            throw new NotFoundHttpException('Not found.');
        }
    }
}
