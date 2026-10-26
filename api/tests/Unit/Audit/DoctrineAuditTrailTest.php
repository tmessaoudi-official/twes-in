<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditVocabulary;
use App\Audit\Domain\Model\AuditLog;
use App\Audit\Infrastructure\DoctrineAuditTrail;
use App\Audit\Infrastructure\UndeclaredAuditAction;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * In production an action nobody declared is recorded all the same and said in the log: a shop's action never fails
 * for a missing word, and its row is also the live change open screens reload on, so dropping it would leave them
 * stale with nothing said anywhere.
 */
final class DoctrineAuditTrailTest extends TestCase
{
    public function testOutsideDebugAnUndeclaredActionIsRecordedStagedAndSaidInTheLog(): void
    {
        $company = Uuid::v7();
        [$trail, $persisted, $staged, $logger] = self::trail(debug: false);
        $trail->record(new AuditEntry('invoice', null, 'nobody.declared', null, [], $company));

        self::assertCount(1, $persisted->rows);
        self::assertSame('nobody.declared', $persisted->rows[0]->getAction());
        self::assertSame($company, $persisted->rows[0]->getCompanyId());
        self::assertCount(1, $staged->changes);
        self::assertCount(1, $logger->said);
        self::assertSame('warning', $logger->said[0][0]);
        self::assertStringContainsString('"nobody.declared"', $logger->said[0][1]);
    }

    public function testWhileDebuggingTheSameActionIsRefusedBeforeAnythingIsWritten(): void
    {
        [$trail, $persisted, $staged, $logger] = self::trail(debug: true);
        try {
            $trail->record(new AuditEntry('invoice', null, 'nobody.declared', null));
            self::fail('recorded');
        } catch (UndeclaredAuditAction) {
            self::assertSame([[], [], []], [$persisted->rows, $staged->changes, $logger->said]);
        }
    }

    /** @return array{DoctrineAuditTrail, object{rows: list<AuditLog>}, object{changes: list<LiveChange>}, object{said: list<array{string, string}>}} */
    private static function trail(bool $debug): array
    {
        $persisted = new class {
            /** @var list<AuditLog> */
            public array $rows = [];
        };
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $row) use ($persisted): void {
            self::assertInstanceOf(AuditLog::class, $row);
            $persisted->rows[] = $row;
        });
        $staged = new class implements LiveChanges {
            /** @var list<LiveChange> */
            public array $changes = [];

            public function stage(LiveChange $change): void
            {
                $this->changes[] = $change;
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $said = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                \assert(\is_string($level));
                $this->said[] = [$level, (string) $message];
            }
        };

        return [new DoctrineAuditTrail($entityManager, new RequestStack(), new MockClock(), $staged, new AuditVocabulary([]), $logger, $debug), $persisted, $staged, $logger];
    }
}
