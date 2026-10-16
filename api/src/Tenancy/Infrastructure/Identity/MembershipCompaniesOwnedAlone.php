<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Identity;

use App\Identity\Application\Account\CompaniesOwnedAlone;
use App\Identity\Application\Account\OwnedAlone;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Read across the person's companies, so it names each company itself rather than the one a request acts for: an
 * account belongs to no company, and the question is about all of them.
 */
final readonly class MembershipCompaniesOwnedAlone implements CompaniesOwnedAlone
{
    public function __construct(private Connection $connection)
    {
    }

    public function of(Uuid $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name FROM membership m
                JOIN role r ON r.id = m.role_id
                JOIN company c ON c.id = m.company_id
                WHERE m.user_id = :user AND r.name = :owner AND c.status <> :closed
                  AND NOT EXISTS (
                      SELECT 1 FROM membership other JOIN role otherRole ON otherRole.id = other.role_id
                      WHERE other.company_id = m.company_id AND other.user_id <> m.user_id AND otherRole.name = :owner
                  )
                ORDER BY c.name, c.id',
            ['user' => $userId->toRfc4122(), 'owner' => Role::OWNER, 'closed' => Company::STATUS_CLOSED],
        );
        $owned = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id']) || !\is_string($row['name'])) {
                throw new \UnexpectedValueException('A company read without its id or name.');
            }
            $owned[] = new OwnedAlone(Uuid::fromString($row['id']), $row['name']);
        }

        return $owned;
    }
}
