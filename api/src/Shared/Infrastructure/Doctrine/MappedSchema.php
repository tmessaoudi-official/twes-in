<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Provider\SchemaProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * The schema the entities map, which `doctrine:migrations:diff` compares the database with. The migrations run on the
 * owner's connection rather than through the entity manager, which connects as the application, so the library no
 * longer finds the mapping by itself; its own provider for it is internal.
 */
final readonly class MappedSchema implements SchemaProvider
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function createSchema(): Schema
    {
        /** @var list<ClassMetadata<object>> $metadata */
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        usort($metadata, static fn (ClassMetadata $a, ClassMetadata $b): int => $a->getTableName() <=> $b->getTableName());

        return new SchemaTool($this->entityManager)->getSchemaFromMetadata($metadata);
    }
}
