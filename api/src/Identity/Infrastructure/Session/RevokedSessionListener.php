<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Application\Session\ManageSessions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A session its owner ended is invalidated before the firewall reads it, the way SessionTimeoutListener treats an
 * expired one: the request proceeds unauthenticated, so a public page still answers and a protected one asks to sign in.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
final readonly class RevokedSessionListener
{
    public const string RECORDED = '_twes_recorded_session';

    public function __construct(private ManageSessions $sessions)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $recorded = $this->sessions->recorded($session->getId());
        if ($recorded?->isRevoked() ?? false) {
            $session->invalidate();

            return;
        }
        // Read once here, handed on to the recorder after the firewall: a request costs one statement for its session.
        $request->attributes->set(self::RECORDED, $recorded);
    }
}
