<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;
use Doctrine\DBAL\Connection;

/**
 * « Inviter un membre »: done once a second person belongs to the company, or an invitation is still open — sending
 * it is the step; whether it is accepted is the other person's.
 */
final readonly class MembersStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
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
                 OR EXISTS (SELECT 1 FROM invitation WHERE company_id = :company AND accepted_at IS NULL AND expires_at > NOW())',
            ['company' => $company->getId()->toRfc4122()],
        );
    }
}
