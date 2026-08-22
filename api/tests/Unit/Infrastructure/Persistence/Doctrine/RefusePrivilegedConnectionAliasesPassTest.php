<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Infrastructure\Persistence\Doctrine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twes\Infrastructure\Persistence\Doctrine\RefusePrivilegedConnectionAliasesPass;

/**
 * The compiler pass that closes certification finding R22-6.
 *
 * The finding: DoctrineBundle registers an autowiring alias per connection whose id is built from the
 * connection NAME, so a constructor parameter named after the privileged connection is handed the role that
 * owns every tenant-owned table — reachable with no attribute, no service id and no registry call, and
 * therefore invisible to a gate that greps for literal spellings.
 *
 * These cases exercise the pass against a hand-built container rather than the real one, because the property
 * being asserted is about the SHAPE of the container graph and not about Doctrine. The complementary
 * assertion — that the kernel actually registers this pass, ahead of autowiring — is
 * {@see \Twes\Tests\Functional\Tenancy\PrivilegedConnectionAliasWiringTest}, and neither is sufficient alone:
 * this file is blind to whether the pass is wired, and that one is blind to what it does. Both, or neither is
 * enough — the same split `CLAUDE.md` § Gotchas records for the tenant-binding middleware.
 */
#[CoversClass(RefusePrivilegedConnectionAliasesPass::class)]
final class RefusePrivilegedConnectionAliasesPassTest extends TestCase
{
    private const string DEFAULT_ID = 'doctrine.dbal.default_connection';

    private const string PRIVILEGED_ID = 'doctrine.dbal.owner_connection';

    public function testTheArgumentNameAliasForAPrivilegedConnectionIsRemoved(): void
    {
        $container = self::containerWithBothConnections();
        $container->setAlias('Doctrine\DBAL\Connection $ownerConnection', self::PRIVILEGED_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertFalse(
            $container->hasAlias('Doctrine\DBAL\Connection $ownerConnection'),
            'A parameter named after the privileged connection must not resolve: this is R22-6, the route no '
            . 'spelling list can close, because the id is derived from the parameter NAME.',
        );
    }

    public function testTheAttributeAliasForAPrivilegedConnectionIsRemoved(): void
    {
        $container = self::containerWithBothConnections();
        $container->setAlias('Doctrine\DBAL\Connection $owner', self::PRIVILEGED_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertFalse(
            $container->hasAlias('Doctrine\DBAL\Connection $owner'),
            'The attribute route resolves through the same alias id whether the argument is written '
            . 'positionally or by name, so removing the alias closes both spellings at once.',
        );
    }

    public function testTheDefaultConnectionKeepsItsAliases(): void
    {
        $container = self::containerWithBothConnections();
        $container->setAlias('Doctrine\DBAL\Connection $defaultConnection', self::DEFAULT_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertTrue(
            $container->hasAlias('Doctrine\DBAL\Connection $defaultConnection'),
            'The default connection is the RESTRICTED runtime role and is what ordinary code must autowire. '
            . 'A pass that removed its alias too would close the boundary by breaking the application.',
        );
    }

    public function testThePrivilegedConnectionSERVICEItselfSurvives(): void
    {
        $container = self::containerWithBothConnections();
        $container->setAlias('Doctrine\DBAL\Connection $ownerConnection', self::PRIVILEGED_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertTrue(
            $container->hasDefinition(self::PRIVILEGED_ID),
            'Only ALIASES are removed. The connection must stay resolvable by name through ManagerRegistry, '
            . 'because that is how doctrine_migrations.yaml reaches it — and running migrations as a role '
            . 'other than the application credential is the entire reason it exists.',
        );
    }

    public function testAnAliasCHAINToAPrivilegedConnectionIsRemoved(): void
    {
        $container = self::containerWithBothConnections();
        $container->setAlias('some.intermediate.alias', self::PRIVILEGED_ID);
        $container->setAlias('Doctrine\DBAL\Connection $ownerConnection', 'some.intermediate.alias');

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertFalse(
            $container->hasAlias('Doctrine\DBAL\Connection $ownerConnection'),
            'An alias pointing at an alias pointing at the privileged connection is the same hole one '
            . 'indirection down. getAliases() reports each link separately, so a check that did not follow '
            . 'the chain would report the outer link clean.',
        );
    }

    public function testTheDEFAULTIsExemptByIdentityRatherThanByName(): void
    {
        // The connections are named so that the ALPHABETICALLY or conventionally "safe-looking" name is the
        // privileged one. Only a check that asks which id `doctrine.default_connection` actually selects gets
        // this right; one that treats the name "default" as the exempt one gets it exactly backwards.
        $container = new ContainerBuilder();
        $container->register(self::DEFAULT_ID, \stdClass::class);
        $container->register(self::PRIVILEGED_ID, \stdClass::class);
        $container->setParameter('doctrine.connections', [
            'default' => self::PRIVILEGED_ID,
            'restricted' => self::DEFAULT_ID,
        ]);
        $container->setParameter('doctrine.default_connection', 'restricted');
        $container->setAlias('Doctrine\DBAL\Connection $defaultConnection', self::PRIVILEGED_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertFalse(
            $container->hasAlias('Doctrine\DBAL\Connection $defaultConnection'),
            'The connection NAMED "default" is not the exempt one here — the exempt one is whichever id the '
            . 'default-connection parameter selects. Anchoring on the name would exempt a privileged '
            . 'connection that had merely been renamed.',
        );
    }

    public function testAMissingConnectionsParameterIsRefusedRatherThanIgnored(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.default_connection', 'default');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('doctrine.connections');

        new RefusePrivilegedConnectionAliasesPass()->process($container);
    }

    public function testADefaultNamingNoConfiguredConnectionIsRefused(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', ['owner' => self::PRIVILEGED_ID]);
        $container->setParameter('doctrine.default_connection', 'nonexistent');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Refusing to guess');

        new RefusePrivilegedConnectionAliasesPass()->process($container);
    }

    public function testASingleConnectionSetupRemovesNothing(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::DEFAULT_ID, \stdClass::class);
        $container->setParameter('doctrine.connections', ['default' => self::DEFAULT_ID]);
        $container->setParameter('doctrine.default_connection', 'default');
        $container->setAlias('Doctrine\DBAL\Connection $defaultConnection', self::DEFAULT_ID);

        new RefusePrivilegedConnectionAliasesPass()->process($container);

        self::assertTrue(
            $container->hasAlias('Doctrine\DBAL\Connection $defaultConnection'),
            'With no privileged connection there is nothing to refuse, and the pass must be inert rather '
            . 'than defensive.',
        );
    }

    private static function containerWithBothConnections(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(self::DEFAULT_ID, \stdClass::class);
        $container->register(self::PRIVILEGED_ID, \stdClass::class);
        $container->setParameter('doctrine.connections', [
            'default' => self::DEFAULT_ID,
            'owner' => self::PRIVILEGED_ID,
        ]);
        $container->setParameter('doctrine.default_connection', 'default');

        return $container;
    }
}
