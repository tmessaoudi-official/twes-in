<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/SPEC.md § 7, 2026-09-16: no resource reads or writes through API Platform's own Doctrine layer. Every resource
 * is a plain object with its own provider and processor, which reach data through CompanyGuard, so the company filter
 * scopes them and an API Platform query extension would never run. This keeps that true.
 */
final class ApiResourceStateTest extends KernelTestCase
{
    public function testNoResourceGoesThroughApiPlatformsDoctrineLayer(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $resources = $container->get(ResourceMetadataCollectionFactoryInterface::class);
        $entities = $container->get(EntityManagerInterface::class)->getMetadataFactory();

        $violations = [];
        $operations = 0;
        foreach ($container->get(ResourceNameCollectionFactoryInterface::class)->create() as $class) {
            if (class_exists($class) && !$entities->isTransient($class)) {
                $violations[] = "$class is a Doctrine entity";
            }
            foreach ($resources->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    ++$operations;
                    foreach (['provider' => $operation->getProvider(), 'processor' => $operation->getProcessor()] as $kind => $service) {
                        if (\is_string($service) && (str_starts_with($service, 'ApiPlatform\\Doctrine\\') || str_starts_with($service, 'api_platform.doctrine.'))) {
                            $violations[] = "$name: $kind $service";
                        }
                    }
                    if (null !== $operation->getStateOptions()) {
                        $violations[] = "$name: stateOptions";
                    }
                }
            }
        }

        self::assertGreaterThan(50, $operations, 'the resources declare their operations');
        self::assertSame([], $violations);
    }
}
