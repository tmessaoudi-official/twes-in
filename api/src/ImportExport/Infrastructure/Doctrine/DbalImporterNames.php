<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Doctrine;

use App\ImportExport\Application\Undo\ImporterNames;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reads the names straight from the accounts, as the activity journal does: no company owns an account. */
final readonly class DbalImporterNames implements ImporterNames
{
    public function __construct(private Connection $connection)
    {
    }

    public function namesOf(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT id, display_name FROM "user" WHERE id IN (:ids)',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds)],
            ['ids' => ArrayParameterType::STRING],
        );
        $names = [];
        foreach ($rows as $id => $name) {
            if (\is_string($name)) {
                $names[(string) $id] = $name;
            }
        }

        return $names;
    }
}
