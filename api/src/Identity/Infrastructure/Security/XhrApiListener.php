<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Declares every /api request an XMLHttpRequest. Symfony's firewall exception listener saves a "target path"
 * into the session for any safe, non-XHR request it turns away, so that a form login can redirect back — which
 * for a JSON API means a session row per anonymous hit and a session cookie on every 401. It has no switch, but
 * it skips XHR. Angular does not send the header on its own; curl and monitors never will. Runs just before the
 * firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class XhrApiListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && str_starts_with($request->getPathInfo(), '/api/') && !$request->headers->has('X-Requested-With')) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
    }
}
