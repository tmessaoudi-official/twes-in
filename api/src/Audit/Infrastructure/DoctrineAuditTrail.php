<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditLog;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one writer of audit_log rows. The client address comes from the current request (trusted proxies
 * resolve X-Forwarded-For, framework.yaml), or is null outside a request (console commands). Flushing here
 * is deliberate: an auth event must be on disk even when the surrounding request ends in an exception. Every row is
 * also staged as a live change, which open screens hear once the unit of work commits (docs/SPEC.md § 7, 2026-09-17).
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private LiveChanges $liveChanges,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        $row = new AuditLog($entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $this->clock->now(), $this->requestStack->getCurrentRequest()?->getClientIp(), $entry->companyId);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        $this->liveChanges->stage(new LiveChange($entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->companyId));
    }
}
