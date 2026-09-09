<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\CurrentCompany;
use Symfony\Component\Uid\Uuid;

final readonly class RecordLogout
{
    public function __construct(private AuditTrail $audit, private CurrentCompany $currentCompany)
    {
    }

    public function handle(Uuid $userId): void
    {
        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $userId, LoginAudit::LOGOUT, $userId, [], $this->currentCompany->id()));
    }
}
