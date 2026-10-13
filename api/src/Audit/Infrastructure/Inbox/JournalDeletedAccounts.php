<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Inbox;

use App\Identity\Application\Account\FinalizeAccountDeletions;
use App\Identity\Application\Account\ManageAccounts;
use App\Inbox\Application\DeletedAccounts;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** The journal keeps the deletion of every account, under the id it had. */
final readonly class JournalDeletedAccounts implements DeletedAccounts
{
    public function __construct(private Connection $connection)
    {
    }

    public function wasDeleted(Uuid $userId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM audit_log WHERE entity_type = :type AND entity_id = :id AND action = :action',
            ['type' => ManageAccounts::ENTITY_TYPE, 'id' => $userId->toRfc4122(), 'action' => FinalizeAccountDeletions::DELETED],
        );
    }
}
