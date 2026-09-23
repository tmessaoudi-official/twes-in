<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Scanning\Application\PhonePairings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Signing out ends every phone the person lent (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4) at once. A session that
 * expires instead stops the tab's heartbeat, and the pairing lapses within ScanPairing::ALIVE_SECONDS.
 */
final readonly class EndPairingsOnLogout
{
    public function __construct(private PhonePairings $pairings)
    {
    }

    #[AsEventListener]
    public function __invoke(LogoutEvent $event): void
    {
        $account = $event->getToken()?->getUser();
        if ($account instanceof SecurityUser) {
            $this->pairings->endAllOf($account->getId());
        }
    }
}
