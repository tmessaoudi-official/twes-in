<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Session;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserSession;
use App\Identity\Domain\UserSessionRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * What a person sees of where they are signed in, and what they do about it. A session is recorded the first time a
 * request carries it and touched at most every five minutes after, so listing costs one read per request and a write
 * only now and then. Ending a session never touches the PHP session itself: the request that brings it back is refused
 * (`seen` answers Revoked), which needs no raw session id in this table.
 */
final readonly class ManageSessions
{
    public const string ENDED = 'auth.session_ended';
    public const string OTHERS_ENDED = 'auth.sessions_ended';

    private const string TOUCH_EVERY = 'PT5M';

    public function __construct(
        private UserSessionRepository $sessions,
        private UserRepository $users,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.session.absolute_ttl')]
        private int $absoluteTtl,
    ) {
    }

    public function seen(Uuid $userId, string $sessionId, string $device, string $address): SessionStanding
    {
        $now = $this->clock->now();
        $known = $this->sessions->ofSessionId($sessionId);
        if (null === $known) {
            // Only a session not yet recorded needs the account itself, so the usual request reads one row and no more.
            $user = $this->users->ofId($userId);
            if (null === $user) {
                return SessionStanding::Revoked;
            }
            $this->sessions->removeOlderThan($user, $now->sub($this->limit()));
            $this->sessions->save(new UserSession($user, $sessionId, $device, $address, $now));

            return SessionStanding::Active;
        }
        if ($known->isRevoked()) {
            return SessionStanding::Revoked;
        }
        if ($known->getLastSeenAt() <= $now->sub(new \DateInterval(self::TOUCH_EVERY))) {
            $known->touch($now);
            $this->sessions->save($known);
        }

        return SessionStanding::Active;
    }

    /** Whether the person ended this session, asked before the firewall reads it so that a revoked cookie finds no account. */
    public function isRevoked(string $sessionId): bool
    {
        return $this->sessions->ofSessionId($sessionId)?->isRevoked() ?? false;
    }

    /** @return list<SessionEntry> the sessions still within their absolute limit, newest use first */
    public function listFor(User $user, string $currentSessionId): array
    {
        $now = $this->clock->now();
        $live = array_filter(
            $this->sessions->seenSince($user, $now->sub($this->limit())),
            static fn (UserSession $session): bool => !$session->isRevoked(),
        );
        usort($live, static fn (UserSession $a, UserSession $b): int => $b->getLastSeenAt() <=> $a->getLastSeenAt());

        return array_map(static fn (UserSession $session): SessionEntry => new SessionEntry($session, $session->isFor($currentSessionId)), $live);
    }

    /** Ends one of the person's other sessions; false when there is none such, or it is the one asking. */
    public function end(User $user, Uuid $id, string $currentSessionId): bool
    {
        $session = $this->sessions->ofId($id);
        if (null === $session || !$session->getUser()->getId()->equals($user->getId()) || $session->isFor($currentSessionId) || $session->isRevoked()) {
            return false;
        }
        $this->transactions->run(function () use ($session, $user): void {
            $session->revoke($this->clock->now());
            $this->sessions->save($session);
            $this->audit->record(new AuditEntry('user', $user->getId(), self::ENDED, $user->getId(), ['device' => $session->getDevice(), 'address' => $session->getAddress()]));
        });

        return true;
    }

    /** Ends every session of the person but the one asking; how many it ended. */
    public function endOthers(User $user, string $currentSessionId): int
    {
        $others = array_values(array_filter(
            $this->listFor($user, $currentSessionId),
            static fn (SessionEntry $entry): bool => !$entry->current,
        ));
        if ([] === $others) {
            return 0;
        }
        $this->transactions->run(function () use ($others, $user): void {
            $now = $this->clock->now();
            foreach ($others as $entry) {
                $entry->session->revoke($now);
                $this->sessions->save($entry->session);
            }
            $this->audit->record(new AuditEntry('user', $user->getId(), self::OTHERS_ENDED, $user->getId(), ['count' => \count($others)]));
        });

        return \count($others);
    }

    private function limit(): \DateInterval
    {
        return new \DateInterval('PT'.$this->absoluteTtl.'S');
    }
}
