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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * What the owner reads in the activity log of support's visit: the entry, always, then each page read once per
 * app.support.read_recorded_every (a quarter hour as shipped, every read at zero), so the log says where support went
 * without a row for every request a screen makes. Whether a page was already recorded is read from the log itself,
 * never kept in memory, which the worker would carry from one request to the next.
 */
final readonly class RecordSupportVisit implements DeclaresAuditActions
{
    public const string ENTERED = 'support.entered';
    public const string READ = 'support.read';

    public function __construct(
        private AuditTrail $audit,
        private SupportVisits $visits,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.support.read_recorded_every')]
        private string $readRecordedEvery,
    ) {
    }

    public function entered(SupportGrant $grant, Uuid $userId): void
    {
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(ManageSupportAccess::ENTITY_TYPE, $grant->getId(), self::ENTERED, $userId, [], $grant->getCompany()->getId())));
    }

    /** @param string $page what the request read, named by its route */
    public function read(Uuid $grantId, Uuid $companyId, Uuid $userId, string $page): void
    {
        $every = new \DateInterval($this->readRecordedEvery);
        // At zero the log is not asked: it holds moments to the second, so it would still skip a read in the same second.
        if (!self::zero($every) && $this->visits->readSince($companyId, $userId, $page, $this->clock->now()->sub($every))) {
            return;
        }
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(ManageSupportAccess::ENTITY_TYPE, $grantId, self::READ, $userId, ['page' => $page], $companyId)));
    }

    private static function zero(\DateInterval $interval): bool
    {
        $from = new \DateTimeImmutable('@0');

        return $from->add($interval)->getTimestamp() === $from->getTimestamp();
    }

    public function auditActions(): array
    {
        return [
            self::ENTERED => new Recorded(ManageSupportAccess::ENTITY_TYPE, AuditScope::Company),
            // A page read changes nothing a member sees: said to their tabs, it would read every session again on each.
            self::READ => new Recorded(ManageSupportAccess::ENTITY_TYPE, AuditScope::Company, heard: false),
        ];
    }
}
