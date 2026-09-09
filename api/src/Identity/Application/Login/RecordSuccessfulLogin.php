<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\CurrentCompany;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Once the password has been accepted: the counters reset, the login is dated, and the row names the session company. */
final readonly class RecordSuccessfulLogin
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
        $user->recordSuccessfulLogin($this->clock->now());
        $this->users->save($user);
        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $userId, LoginAudit::LOGIN, $userId, [], $this->currentCompany->id()));
    }
}
