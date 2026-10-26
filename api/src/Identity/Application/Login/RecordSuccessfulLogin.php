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
use App\Identity\Application\Account\DeleteOwnAccount;
use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\CurrentCompany;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Once the password has been accepted: the counters reset, the login is dated, and the row names the session company. */
final readonly class RecordSuccessfulLogin implements DeclaresAuditActions
{
    public function __construct(
        private UserRepository $users,
        private AuditTrail $audit,
        private CurrentCompany $currentCompany,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Uuid $userId): void
    {
        $user = $this->users->ofId($userId) ?? throw new \DomainException('A login succeeded for a user that does not exist.');
        $now = $this->clock->now();
        $user->recordSuccessfulLogin($now);
        // Signing in again before the deletion a person asked for is final takes it back: the mail says so.
        $cancelled = $user->isDeletionPending();
        if ($cancelled) {
            $user->cancelDeletion($now);
        }
        $this->users->save($user);
        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $userId, LoginAudit::LOGIN, $userId, [], $this->currentCompany->id()));
        if ($cancelled) {
            $this->audit->record(new AuditEntry(ManageAccounts::ENTITY_TYPE, $userId, DeleteOwnAccount::CANCELLED, $userId));
        }
    }

    public function auditActions(): array
    {
        return [
            DeleteOwnAccount::CANCELLED => AuditScope::Outside,
            LoginAudit::LOGIN => AuditScope::Either,
        ];
    }
}
