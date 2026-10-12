<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Tenancy;

use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportVisits;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/** Reads the activity log for Tenancy's support visits, through the company and moment index every company read uses. */
final readonly class DbalSupportVisits implements SupportVisits
{
    public function __construct(private Connection $connection)
    {
    }

    public function readSince(Uuid $companyId, Uuid $userId, string $page, \DateTimeImmutable $since): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM audit_log WHERE company_id = :company AND at >= :since AND action = :action AND actor_user_id = :actor AND changes->>'page' = :page LIMIT 1",
            ['company' => $companyId->toRfc4122(), 'since' => $since, 'action' => RecordSupportVisit::READ, 'actor' => $userId->toRfc4122(), 'page' => $page],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }
}
