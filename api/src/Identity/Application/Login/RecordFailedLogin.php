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
use App\Identity\Application\Account\AccountChanges;
use App\Identity\Application\Mfa\SecondFactorLockout;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\AddressLockRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\RealtimeCuts;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The password's lockout: consecutive wrong credentials lock it for a while, from the address they came from. A wrong
 * password counts, and so does a wrong second-factor code — both are guesses at the same login, and a code that could
 * be guessed forever at the limiter's pace is guessed eventually (docs/SPEC.md § 8 row 22, review S6). Only a wrong
 * credential counts: a refusal because the password is already locked (or the account disabled, or throttled) must not
 * touch the counter, otherwise retrying would extend the lock without end, which is a way to keep someone out of their
 * own account.
 *
 * Five from one address lock the password from that address alone (`AddressLockRepository`), so whoever guesses cannot
 * keep its owner out from another. The account counts too, but only each address's first five, and its far higher
 * ceiling locks the password everywhere: what guesses spread over many addresses meet. One address guessing on, a guess
 * each time its lock ends, never reaches it.
 *
 * The sign-in never says a password is locked, which would say the address is real: its owner is told by mail, once,
 * by the guess that first locks an address and by the one locking it everywhere, never by the ones refused while it
 * holds, so a lock is no way to flood a mailbox.
 */
final readonly class RecordFailedLogin implements DeclaresAuditActions
{
    public function __construct(
        private UserRepository $users,
        private AddressLockRepository $addresses,
        private CallerAddress $caller,
        private AuditTrail $audit,
        private SecurityNotices $notices,
        private AccountChanges $accounts,
        private RealtimeCuts $cuts,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.auth.lock_after_failures')]
        private int $lockAfterFailures,
        #[Autowire(param: 'app.auth.account_lock_after_failures')]
        private int $accountLockAfterFailures,
        #[Autowire(param: 'app.auth.lock_duration')]
        private string $lockDuration,
    ) {
    }

    public function handle(FailedLoginAttempt $attempt): void
    {
        // A unit of its own: the guess that sets the lock changes what the account's row shows, which is said when it commits.
        $this->transactions->run(fn () => $this->record($attempt));
    }

    private function record(FailedLoginAttempt $attempt): void
    {
        $user = null === $attempt->userId ? null : $this->users->ofId($attempt->userId);
        $changes = ['email' => $attempt->email, 'reason' => $attempt->reason];

        if (null !== $user && $attempt->wrongCredential) {
            $now = $this->clock->now();
            $lockFor = new \DateInterval($this->lockDuration);
            $count = $this->addresses->recordFailure($user->getId(), $this->caller->key(), $now, $this->lockAfterFailures, $now->add($lockFor));
            $everywhere = null !== $count && $count <= $this->lockAfterFailures
                && $user->recordFailedLogin($now, $this->accountLockAfterFailures, $lockFor);
            $this->users->save($user);
            $locked = null !== $count && ($everywhere || $count >= $this->lockAfterFailures);
            $changes['failed_login_count'] = $count;
            $changes['locked'] = $locked;
            if ($locked) {
                $this->tell($user->getId(), $attempt, $everywhere, $count === $this->lockAfterFailures, $everywhere ? $user->getLockedUntil() : $now->add($lockFor));
                $this->accounts->said($user->getId(), AccountChanges::LOCKED);
                // Whoever guessed may hold a connection: it is closed, and only a session that still stands renews it.
                $this->cuts->cut($user->getId());
            }
        }

        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $user?->getId(), LoginAudit::LOGIN_FAILED, null, $changes));
    }

    /** Told when the password is first locked from an address, and when it is locked everywhere; not when an address locks again. */
    private function tell(Uuid $userId, FailedLoginAttempt $attempt, bool $everywhere, bool $first, ?\DateTimeImmutable $until): void
    {
        $code = SecondFactorLockout::WRONG_CODE === $attempt->reason;
        $change = match (true) {
            $everywhere => $code ? SecurityChange::SecondFactorLocked : SecurityChange::AccountLocked,
            $first => $code ? SecurityChange::SecondFactorAddressLocked : SecurityChange::AddressLocked,
            default => null,
        };
        if (null !== $change) {
            $this->notices->tell($userId, $change, until: $until);
        }
    }

    public function auditActions(): array
    {
        return [
            LoginAudit::LOGIN_FAILED => new Recorded(LoginAudit::ENTITY_TYPE, AuditScope::Outside),
        ];
    }
}
