<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\AutowirePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twes\Infrastructure\Persistence\Doctrine\RefusePrivilegedConnectionAliasesPass;
use Twes\Kernel;

/**
 * That the kernel actually REGISTERS the privileged-alias pass, and registers it early enough to matter.
 *
 * {@see \Twes\Tests\Unit\Infrastructure\Persistence\Doctrine\RefusePrivilegedConnectionAliasesPassTest} proves
 * what the pass DOES against a hand-built container, and is blind to whether anything ever runs it. This file
 * is the other half and is equally partial on its own: it asserts the wiring and would pass against a pass
 * that removed the wrong aliases. Neither is sufficient, which is the split `CLAUDE.md` § Gotchas records for
 * the tenant-binding middleware — a behaviour test and a wiring test are not substitutes, and the gap between
 * them is where a control with no call site lived for three commits.
 *
 * **The ordering assertion is the interesting one.** The pass must run before `AutowirePass`, because if the
 * arguments have already been wired then removing the alias afterwards changes nothing that matters. Asserting
 * "priority is 100" would pin a NUMBER; asserting that the pass sorts ahead of the autowiring pass in the
 * container's own compiled ordering pins the PROPERTY, and survives Symfony renumbering its own passes.
 */
#[CoversClass(RefusePrivilegedConnectionAliasesPass::class)]
final class PrivilegedConnectionAliasWiringTest extends TestCase
{
    public function testTheKernelRegistersThePass(): void
    {
        self::assertNotNull(
            self::registeredPassPosition()['pass'],
            'Kernel::build() must register RefusePrivilegedConnectionAliasesPass. Without it the container '
            . 'keeps an autowiring alias for the table-owning connection, and a constructor parameter named '
            . 'after that connection is handed the role that can DROP POLICY on every tenant-owned table.',
        );
    }

    public function testItRunsBeforeAutowiring(): void
    {
        ['pass' => $pass, 'autowireStage' => $autowireStage] = self::registeredPassPosition();

        self::assertNotNull(
            $pass,
            'The pass must be registered in the BEFORE-OPTIMIZATION stage. That stage — not the priority — is '
            . 'what puts it ahead of autowiring, and the alias must be gone before autowiring looks for it: '
            . 'running afterwards would leave the arguments already wired to the privileged connection, so '
            . 'the removal would be cosmetic instead of a container COMPILE ERROR naming the argument.',
        );
        self::assertSame(
            'Optimization',
            $autowireStage,
            'and AutowirePass must still be in the OPTIMIZATION stage, which Symfony runs strictly after the '
            . 'before-optimization one. If Symfony ever moved it earlier, the guarantee above would silently '
            . 'stop holding while every other assertion here stayed green — so the location is pinned rather '
            . 'than assumed.',
        );
    }

    public function testThePassTheKernelRegisteredActuallyClosesTheRoute(): void
    {
        $container = self::containerWithAPrivilegedAlias();
        $pass = self::registeredPassPosition()['pass'];

        self::assertInstanceOf(RefusePrivilegedConnectionAliasesPass::class, $pass);

        $pass->process($container);

        self::assertFalse(
            $container->hasAlias('Doctrine\DBAL\Connection $ownerConnection'),
            'End to end through the kernel\'s own registration rather than a locally constructed pass: the '
            . 'object the application will actually run must close the parameter-name route (R22-6).',
        );
        self::assertTrue(
            $container->hasAlias('Doctrine\DBAL\Connection $defaultConnection'),
            'and must leave the restricted runtime connection autowirable, or it closes the boundary by '
            . 'breaking every consumer.',
        );
    }

    /**
     * The pass as the kernel registers it, plus the stage Symfony puts `AutowirePass` in.
     *
     * Both are read from a container the kernel's own `build()` has been applied to, so this asserts the
     * WIRING rather than re-deriving what the wiring ought to be.
     *
     * @return array{pass: RefusePrivilegedConnectionAliasesPass|null, autowireStage: string|null}
     */
    private static function registeredPassPosition(): array
    {
        $container = new ContainerBuilder();

        $kernel = new Kernel('test', true);
        new \ReflectionMethod($kernel, 'build')->invoke($kernel, $container);

        $config = $container->getCompilerPassConfig();

        $found = null;

        foreach ($config->getBeforeOptimizationPasses() as $pass) {
            if ($pass instanceof RefusePrivilegedConnectionAliasesPass) {
                $found = $pass;

                break;
            }
        }

        $autowireStage = null;

        /** @var array<string, list<object>> $stages */
        $stages = [
            'BeforeOptimization' => $config->getBeforeOptimizationPasses(),
            'Optimization' => $config->getOptimizationPasses(),
            'BeforeRemoving' => $config->getBeforeRemovingPasses(),
            'Removing' => $config->getRemovingPasses(),
            'AfterRemoving' => $config->getAfterRemovingPasses(),
        ];

        foreach ($stages as $stage => $passes) {
            foreach ($passes as $pass) {
                if ($pass instanceof AutowirePass) {
                    $autowireStage ??= $stage;
                }
            }
        }

        return ['pass' => $found, 'autowireStage' => $autowireStage];
    }

    private static function containerWithAPrivilegedAlias(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.dbal.default_connection', \stdClass::class);
        $container->register('doctrine.dbal.owner_connection', \stdClass::class);
        $container->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'owner' => 'doctrine.dbal.owner_connection',
        ]);
        $container->setParameter('doctrine.default_connection', 'default');
        $container->setAlias('Doctrine\DBAL\Connection $ownerConnection', 'doctrine.dbal.owner_connection');
        $container->setAlias('Doctrine\DBAL\Connection $defaultConnection', 'doctrine.dbal.default_connection');

        return $container;
    }
}
