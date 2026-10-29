<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Identity;

use App\Identity\Application\Account\CompanyOwners;
use App\Tenancy\Domain\Model\Role;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Read across every company, whatever its state: an owner of a company awaiting approval or closed is an owner still. */
final readonly class MembershipCompanyOwners implements CompanyOwners
{
    public function __construct(private Connection $connection)
    {
    }

    public function ownsACompany(Uuid $userId): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM membership m JOIN role r ON r.id = m.role_id WHERE m.user_id = :user AND r.name = :owner)',
            ['user' => $userId->toRfc4122(), 'owner' => Role::OWNER],
        );
    }
}
