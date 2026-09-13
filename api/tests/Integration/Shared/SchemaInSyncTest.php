<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrations and the entity mapping describe the same schema. A mapping that drifts from its migration (an
 * index named differently, a default left out, a partial index's predicate written another way) makes the next
 * generated migration propose changes nobody asked for; this fails first.
 */
final class SchemaInSyncTest extends KernelTestCase
{
    public function testTheMigratedDatabaseIsWhatTheMappingDescribes(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        // The migrations bundle's own bookkeeping table is hidden from its console commands, not from a SchemaTool.
        $statements = array_values(array_filter($statements, static fn (string $sql): bool => !str_contains($sql, 'doctrine_migration_versions')));

        self::assertSame([], $statements, "The mapping differs from the migrated schema:\n".implode("\n", $statements));
    }
}
