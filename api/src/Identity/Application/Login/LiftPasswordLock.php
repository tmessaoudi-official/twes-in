<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Account\AccountNotFound;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The lock wrong guesses set on a password, lifted by someone in charge of the account rather than by time or the mailed
 * link (row 241): a company's owner for one of its members, the platform's operator for an owner. The wrong guesses are
 * forgotten with it, as a password reset forgets them. Recorded in the company's journal when an owner lifts it, outside
 * any company when the operator does, and the account is told who let it back in.
 */
final readonly class LiftPasswordLock implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'user';
    public const string LIFTED = 'account.lock_lifted';

    public function __construct(
        private UserRepository $users,
        private AuditTrail $audit,
        private SecurityNotices $notices,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * Whether a lock held and was lifted: a password no longer locked is left as it is, and nothing is recorded or told.
     *
     * @throws AccountNotFound
     */
    public function lift(Uuid $userId, Uuid $actorUserId, ?Uuid $companyId, SecurityChange $told): bool
    {
        return $this->transactions->run(function () use ($userId, $actorUserId, $companyId, $told): bool {
            $user = $this->users->ofId($userId) ?? throw new AccountNotFound(\sprintf('No account %s.', $userId->toRfc4122()));
            $now = $this->clock->now();
            if (!$user->isLockedAt($now)) {
                return false;
            }
            $user->unlock($now);
            $this->users->save($user);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $user->getId(), self::LIFTED, $actorUserId, [], $companyId));
            $this->notices->tell($user->getId(), $told);

            return true;
        });
    }

    public function auditActions(): array
    {
        return [self::LIFTED => new Recorded(self::ENTITY_TYPE, AuditScope::Either)];
    }
}
