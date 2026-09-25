<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every entity's mapping is one Doctrine accepts: `doctrine:schema:validate` run in the suite, its mapping half. The
 * database half is the migrations' business. Without this, an association mapped on one side only
 * (`Invoice#corrections`, 2026-09-25) boots, answers and passes every test while Doctrine calls it invalid.
 */
final class DoctrineMappingTest extends KernelTestCase
{
    public function testEveryEntityIsMappedTheWayDoctrineValidatesIt(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $mapped = $entityManager->getMetadataFactory()->getAllMetadata();
        self::assertGreaterThan(40, \count($mapped), 'the entities were read');

        self::assertSame([], (new SchemaValidator($entityManager))->validateMapping());
    }
}
