<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Application\Session\ManageSessions;
use App\Identity\Domain\UserSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Signing out ends the session's record with it, so the devices list stops showing a browser that is no longer signed
 * in. The record is the one `RevokedSessionListener` read before the firewall, which outlives the session's own id.
 */
final readonly class EndSessionRecordOnLogout
{
    public function __construct(private ManageSessions $sessions)
    {
    }

    #[AsEventListener]
    public function __invoke(LogoutEvent $event): void
    {
        $recorded = $event->getRequest()->attributes->get(RevokedSessionListener::RECORDED);
        if ($recorded instanceof UserSession) {
            $this->sessions->signedOut($recorded);
        }
    }
}
