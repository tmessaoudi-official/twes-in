<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * « Inviter un membre »: done once a second person belongs to the company, or an invitation is still open — sending
 * it is the step; whether it is accepted is the other person's. Open is what accepting it says (`Invitation::isUsableAt`),
 * on the application's clock, never the database's.
 */
final readonly class MembersStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function key(): string
    {
        return 'company.members';
    }

    public function position(): int
    {
        return 60;
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return MemberPermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT (SELECT COUNT(*) FROM membership WHERE company_id = :company) > 1
                 OR EXISTS (SELECT 1 FROM invitation WHERE company_id = :company AND accepted_at IS NULL AND expires_at >= :now)',
            ['company' => $company->getId()->toRfc4122(), 'now' => $this->clock->now()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
