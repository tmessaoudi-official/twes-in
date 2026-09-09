<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Idle and absolute session limits. Runs before the firewall (priority 8): an expired session is invalidated
 * here, so the context listener finds no token and the request proceeds unauthenticated. Only sessions the
 * client already had are examined; a request without a session cookie starts nothing.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final readonly class SessionTimeoutListener
{
    public function __construct(
        private ClockInterface $clock,
        #[Autowire(param: 'app.session.idle_ttl')] private int $idleTtl,
        #[Autowire(param: 'app.session.absolute_ttl')] private int $absoluteTtl,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start(); // loads the metadata; the firewall would start it a moment later anyway
        }
        $meta = $session->getMetadataBag();
        $now = $this->clock->now()->getTimestamp();
        if ($now - $meta->getLastUsed() > $this->idleTtl || $now - $meta->getCreated() > $this->absoluteTtl) {
            $session->invalidate();
        }
    }
}
