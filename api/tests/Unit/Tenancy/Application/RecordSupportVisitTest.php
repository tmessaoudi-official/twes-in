<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportVisits;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * How often a page support reads is written to the owner's activity log is the operator's (app.support.read_recorded_every):
 * a quarter hour as shipped, every read at its floor of zero, an hour at most. The log is read for the last time a page
 * was recorded, so the window is what is asked of it.
 */
final class RecordSupportVisitTest extends TestCase
{
    /** @var \ArrayObject<int, \DateTimeImmutable> the moments the log was asked from */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        $this->asked = new \ArrayObject();
    }

    public function testAPageIsLookedUpOverTheLastQuarterHourAsShipped(): void
    {
        $this->visit('PT15M', alreadyRead: true)->read(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'api_customers_get_collection');

        self::assertEquals([new \DateTimeImmutable('2026-11-02 11:45:00')], $this->asked->getArrayCopy());
    }

    public function testAPageIsLookedUpOverTheWindowTheOperatorSets(): void
    {
        $this->visit('PT1H', alreadyRead: true)->read(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'api_customers_get_collection');

        self::assertEquals([new \DateTimeImmutable('2026-11-02 11:00:00')], $this->asked->getArrayCopy());
    }

    public function testAPageAlreadyRecordedInTheWindowIsNotRecordedAgain(): void
    {
        $audit = new InMemoryAuditTrail();
        $this->visit('PT15M', alreadyRead: true, audit: $audit)->read(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'api_customers_get_collection');

        self::assertSame([], $audit->entries);
    }

    /** At zero every read is recorded: the log holds moments to the second, so asking it would still skip a read in the same second. */
    public function testAtZeroEveryReadIsRecordedWithoutAskingTheLog(): void
    {
        $audit = new InMemoryAuditTrail();
        $visit = $this->visit('PT0M', alreadyRead: true, audit: $audit);
        $grant = Uuid::v7();
        $company = Uuid::v7();
        $user = Uuid::v7();

        $visit->read($grant, $company, $user, 'api_customers_get_collection');
        $visit->read($grant, $company, $user, 'api_customers_get_collection');

        self::assertSame([], $this->asked->getArrayCopy());
        self::assertSame([RecordSupportVisit::READ, RecordSupportVisit::READ], array_map(static fn ($entry): string => $entry->action, $audit->entries));
    }

    private function visit(string $every, bool $alreadyRead, ?InMemoryAuditTrail $audit = null): RecordSupportVisit
    {
        $visits = new class($alreadyRead, $this->asked) implements SupportVisits {
            /** @param \ArrayObject<int, \DateTimeImmutable> $asked */
            public function __construct(private readonly bool $alreadyRead, private readonly \ArrayObject $asked)
            {
            }

            public function readSince(Uuid $companyId, Uuid $userId, string $page, \DateTimeImmutable $since): bool
            {
                $this->asked[] = $since;

                return $this->alreadyRead;
            }
        };

        return new RecordSupportVisit($audit ?? new InMemoryAuditTrail(), $visits, new FakeTransactions(), new MockClock('2026-11-02 12:00:00'), $every);
    }
}
