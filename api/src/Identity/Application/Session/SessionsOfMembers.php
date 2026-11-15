<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Session;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Model\UserSession;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\Repository\UserSessionRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A member's sessions as their company's owner sees them: the ones still live that have entered that company, never
 * those another company of the same account was used in, which a sign-in must prove itself for before it acts there.
 * The owner ends one or all of them for a device lost or left signed in; the member signs in again, is told by mail who
 * signed them out, and the company's journal records it. Who may ask is the companies' context's to judge.
 */
final readonly class SessionsOfMembers implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'user';
    public const string ENDED = 'account.sessions_ended_by_owner';

    public function __construct(
        private UserSessionRepository $sessions,
        private UserRepository $users,
        private AuditTrail $audit,
        private SecurityNotices $notices,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.session.absolute_ttl')]
        private int $absoluteTtl,
    ) {
    }

    /** @return list<UserSession> the member's live sessions that entered the company, newest use first */
    public function enteredIn(Uuid $userId, Uuid $companyId): array
    {
        $user = $this->users->ofId($userId);
        if (null === $user) {
            return [];
        }
        $since = $this->clock->now()->sub(new \DateInterval('PT'.$this->absoluteTtl.'S'));
        $live = array_values(array_filter(
            $this->sessions->seenSince($user, $since),
            static fn (UserSession $session): bool => !$session->isRevoked() && $session->hasEntered($companyId),
        ));
        usort($live, static fn (UserSession $a, UserSession $b): int => $b->getLastSeenAt() <=> $a->getLastSeenAt());

        return $live;
    }

    /**
     * Ends the one session named, or every one the owner is shown when none is; how many it ended, 0 when the one named
     * is not among them, and then nothing is recorded or told.
     */
    public function end(Uuid $userId, ?Uuid $sessionId, Uuid $ownerUserId, Uuid $companyId): int
    {
        return $this->transactions->run(function () use ($userId, $sessionId, $ownerUserId, $companyId): int {
            $ending = array_values(array_filter(
                $this->enteredIn($userId, $companyId),
                static fn (UserSession $session): bool => null === $sessionId || $session->getId()->equals($sessionId),
            ));
            if ([] === $ending) {
                return 0;
            }
            $now = $this->clock->now();
            foreach ($ending as $session) {
                $session->revoke($now);
                $this->sessions->save($session);
            }
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $userId, self::ENDED, $ownerUserId, [], $companyId));
            $this->notices->tell($userId, SecurityChange::SessionsEndedByOwner);

            return \count($ending);
        });
    }

    public function auditActions(): array
    {
        return [self::ENDED => new Recorded(self::ENTITY_TYPE, AuditScope::Company)];
    }
}
