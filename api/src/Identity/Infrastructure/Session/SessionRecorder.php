<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Application\Session\ManageSessions;
use App\Identity\Domain\UserSession;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records the session of a signed-in request for the person's list of devices. Runs after the firewall, which has by
 * then said who the request is; a request without an account, or without a session, records nothing.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class SessionRecorder
{
    public function __construct(private ManageSessions $sessions, private Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return;
        }
        $recorded = $request->attributes->get(RevokedSessionListener::RECORDED);
        $this->sessions->seen($account->getId(), $session->getId(), $recorded instanceof UserSession ? $recorded : null, (string) $request->headers->get('User-Agent', ''), (string) $request->getClientIp());
    }
}
