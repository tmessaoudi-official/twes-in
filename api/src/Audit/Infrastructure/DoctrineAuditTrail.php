<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\AuditVocabulary;
use App\Audit\Domain\Model\AuditLog;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one writer of audit_log rows. The client address comes from the current request (trusted proxies
 * resolve X-Forwarded-For, framework.yaml), or is null outside a request (console commands). Flushing here
 * is deliberate: an auth event must be on disk even when the surrounding request ends in an exception. Every row is
 * also staged as a live change, which open screens hear once the unit of work commits (docs/SPEC.md § 7, 2026-09-17).
 * An action nobody declares, or one recorded where or on what its declaration says it is not, is refused while the
 * kernel debugs (the tests, a developer's stack, the browser scenarios), so it is found before a journal shows it as its
 * raw name; in production it is recorded all the same and said in the log, as a person's action never fails for a
 * missing word.
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private LiveChanges $liveChanges,
        private AuditVocabulary $vocabulary,
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        $this->assertDeclared($entry);
        $row = new AuditLog($entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $this->clock->now(), $this->requestStack->getCurrentRequest()?->getClientIp(), $entry->companyId);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        // Append-only and never read back in the request: managed, it would be walked by every later flush, and a
        // request writing many (an import) would cost the square of their number.
        $this->entityManager->detach($row);
        $this->liveChanges->stage(new LiveChange($entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->companyId));
    }

    private function assertDeclared(AuditEntry $entry): void
    {
        $scope = $this->vocabulary->scopeOf($entry->action);
        $inACompany = null !== $entry->companyId;
        $kinds = $this->vocabulary->kindsOf($entry->action);
        if (null !== $scope && $scope->allows($inACompany) && \in_array($entry->entityType, $kinds, true)) {
            return;
        }
        $said = match (true) {
            null === $scope => \sprintf('The audit action "%s" is declared nowhere: add it to the auditActions() of the class that records it.', $entry->action),
            !$scope->allows($inACompany) => \sprintf('The audit action "%s" is declared %s but recorded %s a company: correct its declaration or the entry.', $entry->action, $scope->value, $inACompany ? 'in' : 'outside'),
            default => \sprintf('The audit action "%s" is declared on %s but recorded on "%s": correct its declaration or the entry.', $entry->action, implode(', ', $kinds), $entry->entityType),
        };
        if ($this->debug) {
            throw new UndeclaredAuditAction($said);
        }
        $this->logger->warning($said, ['action' => $entry->action]);
    }
}
