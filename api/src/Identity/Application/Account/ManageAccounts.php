<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\AccountSearch;
use App\Shared\Application\Transactions;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

/**
 * What an operator does about an account, audited against it (docs/SPEC.md § 7, 2026-09-15): ends its sessions,
 * deactivates it, which ends them too, and reactivates it. A company's owner removes someone from the company
 * instead and never reaches the account. Asking for the state an account is already in changes and audits nothing.
 * The demo's throwaway owners are no operator's concern, so the list leaves them out.
 */
final readonly class ManageAccounts implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'user';
    public const string SESSIONS_ENDED = 'account.sessions_ended';
    public const string DEACTIVATED = 'account.deactivated';
    public const string REACTIVATED = 'account.reactivated';

    public function __construct(private UserRepository $users, private AuditTrail $audit, private Transactions $transactions, private DemoAccounts $demos, private SecurityNotices $notices)
    {
    }

    /**
     * One page of the accounts, narrowed by words and choices and sorted in the database.
     *
     * @return Page<AccountView>
     */
    public function search(AccountSearch $search, PageRequest $page): Page
    {
        return $this->users->search($search->leavingOut($this->demos->all()), $page)->map(AccountView::of(...));
    }

    /** @throws AccountNotFound */
    public function endSessions(Uuid $userId, Uuid $operatorId): AccountView
    {
        return $this->transactions->run(function () use ($userId, $operatorId): AccountView {
            $user = $this->accountOf($userId);
            $user->rotateSecurityStamp();
            $this->users->save($user);
            $this->record($user, self::SESSIONS_ENDED, $operatorId);
            $this->notices->tell($user->getId(), SecurityChange::OperatorEndedSessions);

            return AccountView::of($user);
        });
    }

    /** @throws AccountNotFound|OwnAccount */
    public function deactivate(Uuid $userId, Uuid $operatorId): AccountView
    {
        return $this->transactions->run(function () use ($userId, $operatorId): AccountView {
            $user = $this->accountOf($userId);
            if ($user->getId()->equals($operatorId)) {
                throw new OwnAccount('An operator cannot deactivate their own account.');
            }
            if ($user->isActive()) {
                $user->setActive(false);
                $user->rotateSecurityStamp();
                $this->users->save($user);
                $this->record($user, self::DEACTIVATED, $operatorId);
                $this->notices->tell($user->getId(), SecurityChange::OperatorDeactivated);
            }

            return AccountView::of($user);
        });
    }

    /** @throws AccountNotFound */
    public function reactivate(Uuid $userId, Uuid $operatorId): AccountView
    {
        return $this->transactions->run(function () use ($userId, $operatorId): AccountView {
            $user = $this->accountOf($userId);
            if (!$user->isActive()) {
                $user->setActive(true);
                $this->users->save($user);
                $this->record($user, self::REACTIVATED, $operatorId);
                $this->notices->tell($user->getId(), SecurityChange::OperatorReactivated);
            }

            return AccountView::of($user);
        });
    }

    private function accountOf(Uuid $userId): User
    {
        return $this->users->ofId($userId) ?? throw new AccountNotFound(\sprintf('No account %s.', $userId->toRfc4122()));
    }

    private function record(User $user, string $action, Uuid $operatorId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $user->getId(), $action, $operatorId, ['email' => $user->getEmail()->value]));
    }

    public function auditActions(): array
    {
        return [
            self::DEACTIVATED => new Recorded(self::ENTITY_TYPE, AuditScope::Outside),
            self::REACTIVATED => new Recorded(self::ENTITY_TYPE, AuditScope::Outside),
            self::SESSIONS_ENDED => new Recorded(self::ENTITY_TYPE, AuditScope::Outside),
        ];
    }
}
