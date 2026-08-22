<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twes\Infrastructure\Persistence\Doctrine\RefusePrivilegedConnectionAliasesPass;

/**
 * The Symfony application kernel.
 *
 * **Hand-written, because `symfony/flex` is deliberately not a dependency.** Flex generates this file and the
 * `config/` tree from recipes, and it is a Composer *plugin* — plugins are disabled in this container for
 * safety, and a generated file nobody chose is a file nobody can explain. Every entry under `config/` here
 * exists because something needs it, which is the same argument § Architecture makes for XML mapping over
 * attributes: the wiring is visible rather than implied.
 *
 * `MicroKernelTrait` gives `configureContainer()` and `configureRoutes()` and the conventional
 * `config/{packages}/*.yaml` loading, so this class stays the two hooks and nothing else.
 *
 * **Note what is NOT here: the kernel knows nothing about tenancy.** Binding a tenant is a per-REQUEST and
 * per-CONNECTION concern, not a boot-time one, and `PostgresRowLevelSecurityIsolation` documents the window as
 * one connection lifetime. Wiring it into the kernel would make it a global, which is exactly the ambient
 * access § Architecture forbids.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Registers the one compiler pass this application owns.
     *
     * **A tenancy control, not a wiring convenience**, which is why it is here rather than expressed as
     * configuration. {@see RefusePrivilegedConnectionAliasesPass} removes the autowiring aliases that resolve
     * to a non-default Doctrine connection, so neither the attribute route nor a constructor parameter named
     * after the privileged one can still reach the role that owns every tenant-owned table. The two fail
     * DIFFERENTLY — the attribute is a compile error, the parameter name falls back to the default,
     * restricted connection — and both are fail-safe; the class docblock has the measurements and the
     * round-22 finding this closes.
     *
     * Running ahead of `AutowirePass` is what makes the removal matter rather than being cosmetic, and that
     * is bought by the pass STAGE — the before-optimization default — rather than by the priority, which
     * only orders it against other passes in the same stage. The distinction is written out on the constant,
     * because the first version of this comment credited it to the priority and was wrong.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new RefusePrivilegedConnectionAliasesPass(),
            priority: RefusePrivilegedConnectionAliasesPass::PRIORITY,
        );
    }
}
