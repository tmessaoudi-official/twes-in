<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\SupportGrant;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What the owner reads in the activity log of support's visit: the entry, then each page read, once per quarter hour,
 * so the log says where support went without a row for every request a screen makes. Whether a page was already
 * recorded is read from the log itself, never kept in memory, which the worker would carry from one request to the next.
 */
final readonly class RecordSupportVisit implements DeclaresAuditActions
{
    public const string ENTERED = 'support.entered';
    public const string READ = 'support.read';

    private const string ONCE_PER = '-15 minutes';

    public function __construct(
        private AuditTrail $audit,
        private SupportVisits $visits,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function entered(SupportGrant $grant, Uuid $userId): void
    {
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(ManageSupportAccess::ENTITY_TYPE, $grant->getId(), self::ENTERED, $userId, [], $grant->getCompany()->getId())));
    }

    /** @param string $page what the request read, named by its route */
    public function read(Uuid $grantId, Uuid $companyId, Uuid $userId, string $page): void
    {
        if ($this->visits->readSince($companyId, $userId, $page, $this->clock->now()->modify(self::ONCE_PER))) {
            return;
        }
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(ManageSupportAccess::ENTITY_TYPE, $grantId, self::READ, $userId, ['page' => $page], $companyId)));
    }

    public function auditActions(): array
    {
        return [
            self::ENTERED => new Recorded(ManageSupportAccess::ENTITY_TYPE, AuditScope::Company),
            self::READ => new Recorded(ManageSupportAccess::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
