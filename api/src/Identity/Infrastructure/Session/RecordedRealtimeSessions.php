<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Domain\Model\UserSession;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\Repository\UserSessionRepository;
use App\Shared\Application\RealtimeSessions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Every session of the account seen within a session's absolute lifetime, ended ones included: a session older than
 * that has no cookie left to renew a token with, and closing one already closed changes nothing.
 */
final readonly class RecordedRealtimeSessions implements RealtimeSessions
{
    public function __construct(
        private UserRepository $users,
        private UserSessionRepository $sessions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.session.absolute_ttl')]
        private int $absoluteTtl,
    ) {
    }

    public function of(Uuid $userId): array
    {
        $user = $this->users->ofId($userId);
        if (null === $user) {
            return [];
        }
        $since = $this->clock->now()->sub(new \DateInterval('PT'.$this->absoluteTtl.'S'));

        $sessions = $this->sessions->seenSince($user, $since);
        // Oldest first, so the closes are made in an order that reads back the same.
        usort($sessions, static fn (UserSession $a, UserSession $b): int => [$a->getCreatedAt(), $a->getId()->toRfc4122()] <=> [$b->getCreatedAt(), $b->getId()->toRfc4122()]);

        return array_map(static fn (UserSession $session): Uuid => $session->getId(), $sessions);
    }
}
