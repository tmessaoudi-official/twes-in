<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * No answer deletes the session cookie. Symfony's session listener deletes it whenever a request came with one and its
 * session reads empty, but the browser applies a deletion to the cookie's name, not to the session the request carried:
 * a read sent before a sign-in and answered after it (the sign-in replaced that session, so it reads empty) deleted the
 * session the sign-in had just given, and the person was signed out at once. An ended session loses nothing by it: it
 * is told by its answer and by « session ended » on its screens, a sign-out hands over a new cookie of its own, and the
 * id left in the browser is refused, as strict mode starts a new session for an id the server does not hold.
 * Runs after Symfony's session listener (-1000), which writes the cookie.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1010)]
final readonly class KeepSessionCookieListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession()) {
            return;
        }
        $name = $request->getSession()->getName();
        $headers = $event->getResponse()->headers;
        foreach ($headers->getCookies() as $cookie) {
            if ($name === $cookie->getName() && $cookie->isCleared()) {
                $headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
