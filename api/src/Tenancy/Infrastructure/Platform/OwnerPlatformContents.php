<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Platform;

use App\Files\Application\FileStorage;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The in-place emptying, on the schema owner's connection: the application's role may not empty the journal, and
 * TRUNCATE is no privilege of its own. The tables are read from the database, so a table a later migration adds is
 * emptied too, all in one TRUNCATE, which the foreign keys between them allow and the fiscal guard refuses while an
 * issued invoice is kept. Only the migrations list stays, so the schema is the one the migrations left. Then every
 * stored file, local or in a bucket. Only the migrate service holds the owner's address, so the command runs there.
 */
final readonly class OwnerPlatformContents implements PlatformContents
{
    /** Doctrine Migrations' own list of what ran, the default its configuration keeps. */
    private const string MIGRATIONS = 'doctrine_migration_versions';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.owner_connection')] private Connection $owner,
        private FileStorage $storage,
    ) {
    }

    public function empty(): array
    {
        $tables = Rows::texts($this->owner->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> ? ORDER BY tablename",
            [self::MIGRATIONS],
        ));
        if ([] !== $tables) {
            $this->owner->executeStatement('TRUNCATE '.implode(', ', array_map($this->owner->quoteSingleIdentifier(...), $tables)).' RESTART IDENTITY');
        }

        // Listed whole first: a listing read while its files go may skip some.
        $keys = [];
        foreach ($this->storage->keysUnder('') as $key => $written) {
            $keys[] = $key;
        }
        foreach ($keys as $key) {
            $this->storage->delete($key);
        }

        return ['tables' => \count($tables), 'files' => \count($keys)];
    }
}
