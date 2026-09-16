<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * What an operator does about an account, audited against it (docs/SPEC.md § 7, 2026-09-15): ends its sessions,
 * deactivates it, which ends them too, and reactivates it. A company's owner removes someone from the company
 * instead and never reaches the account. Asking for the state an account is already in changes and audits nothing.
 */
final readonly class ManageAccounts
{
    public const string ENTITY_TYPE = 'user';
    public const string SESSIONS_ENDED = 'account.sessions_ended';
    public const string DEACTIVATED = 'account.deactivated';
    public const string REACTIVATED = 'account.reactivated';

    public function __construct(private UserRepository $users, private AuditTrail $audit, private Transactions $transactions)
    {
    }

    /** @return list<AccountView> the accounts whose address or name holds that text, in address order */
    public function find(string $text, int $limit): array
    {
        return array_map(AccountView::of(...), $this->users->search($text, $limit));
    }

    /** @throws AccountNotFound */
    public function endSessions(Uuid $userId, Uuid $operatorId): AccountView
    {
        return $this->transactions->run(function () use ($userId, $operatorId): AccountView {
            $user = $this->accountOf($userId);
            $user->rotateSecurityStamp();
            $this->users->save($user);
            $this->record($user, self::SESSIONS_ENDED, $operatorId);

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
}
