<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes every autowiring alias that resolves to a NON-DEFAULT Doctrine connection.
 *
 * ## Why this is a compiler pass and not another line in a text gate
 *
 * `scripts/gates/no-owner-connection-in-application.php` refuses a set of literal SPELLINGS by which
 * application code could name the table-owning connection. Certification round 22 (R22-6) showed that a
 * spelling list cannot close this boundary, because Symfony resolves one of the routes by **parameter name**
 * rather than by any text a grep could find: DoctrineBundle calls `registerAliasForArgument()` for each
 * connection, so the compiled container carries an alias id built from the connection's name with the word
 * "Connection" appended. [Verified 2026-08-22 in the dev container's `removed-ids.php`, which carries such an
 * id for both the default connection and its privileged sibling.] A constructor parameter with that NAME — no
 * attribute, no service id, no registry call — is handed the role that OWNS every tenant-owned table and can
 * `DROP POLICY` on it. `FORCE ROW LEVEL SECURITY` does not help: it stops an owner SKIPPING policies, not
 * REMOVING them.
 *
 * That is the same polarity error `scripts/gates/worker-mode-blocked.sh` needed three defeats to learn — an
 * enumeration of forbidden forms is fail-OPEN for every form nobody thought of, and here the enumeration
 * cannot even be completed: a positional attribute argument, the same attribute written with a NAMED argument,
 * and the parameter-name alias are three spellings of ONE mechanism, and a future Symfony version may add a
 * fourth.
 *
 * So this pass removes the ALIASES rather than describing them. What remains is the connection SERVICE itself,
 * which must stay in the registry for `doctrine_migrations.yaml` to resolve it by name — that is the entire
 * reason the privileged connection exists, and it is reached through `ManagerRegistry`, never through an alias.
 *
 * ## Why it runs BEFORE autowiring, and what each route actually does then
 *
 * Registered in the before-optimization stage, which Symfony runs in its entirety before the optimization
 * stage where `AutowirePass` lives, so the alias is gone by the time autowiring looks for it.
 *
 * **The two routes then behave DIFFERENTLY, and an earlier version of this docblock said both were a compile
 * error.** They were measured with a throwaway probe service rather than reasoned about:
 *
 *   - `#[Target]` naming a privileged connection is a **container compile error** — *"Cannot autowire … no
 *     such target exists. Did you mean to target `default` instead?"* A named target whose alias is gone has
 *     nothing to fall back to.
 *   - A constructor parameter NAMED after one is **not an error: it silently resolves to the DEFAULT
 *     connection**, because autowiring falls back to the bare type alias when no `Type $name` alias matches.
 *     [Verified 2026-08-22: a probe carrying exactly that parameter reported `current_user` as the restricted
 *     runtime role, not the owner.]
 *
 * Both outcomes are fail-SAFE — neither hands over the privileged role, which is the security property this
 * closes — but they are not the same thing, and the difference matters to whoever is puzzling over why their
 * parameter did not get what its name suggested. Stating the stronger one would also be the defect
 * `CLAUDE.md` § Gotchas records five times over: something written down as reasoned rather than tried.
 *
 * ## The ids are DERIVED, never written down
 *
 * Both the connection map and the name of the default are container parameters published by DoctrineBundle
 * [Verified 2026-08-22 via `debug:container --parameter`]. Deriving from them means a third connection added
 * tomorrow is covered on the day it is added, with no edit here — and it means this file contains no
 * privileged service id, and no attribute spelling, as a literal. That matters twice over: the text gate greps
 * for exactly those strings and does not strip comments, so a docblock naming one of them would make this
 * file's own prose a reported violation. (It did, on the first draft. Kept as a note rather than smoothed
 * away: it is the "comment text read as configuration" shape recorded against another gate's first version.)
 *
 * **It fails CLOSED.** A missing or malformed parameter throws rather than passing, because a pass that
 * silently did nothing would be indistinguishable from one that found nothing to remove — the anti-vacuity
 * rule this project applies to every gate.
 */
final class RefusePrivilegedConnectionAliasesPass implements CompilerPassInterface
{
    /**
     * Early within the BEFORE-OPTIMIZATION stage.
     *
     * **This priority is NOT what puts the pass ahead of autowiring, and an earlier version of this docblock
     * said it was.** `AutowirePass` is registered in the OPTIMIZATION stage [Verified 2026-08-22: it is at
     * index 16 of `getOptimizationPasses()` on a bare `ContainerBuilder`], and Symfony runs every
     * before-optimization pass before that stage begins, so the ordering that matters is bought by the pass
     * TYPE alone — the default of `addCompilerPass()`. The false claim was caught by the wiring test written
     * to assert it, which is the argument for asserting an ordering rather than asserting a number.
     *
     * What the priority does buy is precedence over any OTHER before-optimization pass that this application
     * or a bundle may later add and which reads aliases. That is a smaller claim, and it is the true one.
     */
    public const int PRIORITY = 100;

    private const string CONNECTIONS_PARAMETER = 'doctrine.connections';

    private const string DEFAULT_CONNECTION_PARAMETER = 'doctrine.default_connection';

    public function process(ContainerBuilder $container): void
    {
        $privileged = $this->privilegedConnectionIds($container);

        if ([] === $privileged) {
            return;
        }

        // Decided in FULL before anything is removed, and that ordering is load-bearing rather than tidy.
        // Removing inside the loop mutates the very graph `resolveTarget()` walks: drop an intermediate alias
        // first and the outer alias that pointed THROUGH it then dead-ends at an id that no longer exists,
        // resolves to something not in the privileged set, and survives. A chained alias is the same hole one
        // indirection down, so a pass that closed the inner link and left the outer one would be worse than
        // useless — it would look like it had done the work.
        $doomed = [];

        foreach ($container->getAliases() as $aliasId => $alias) {
            if (!\in_array($this->resolveTarget($container, (string) $alias), $privileged, true)) {
                continue;
            }

            $doomed[] = $aliasId;
        }

        foreach ($doomed as $aliasId) {
            $container->removeAlias($aliasId);
        }
    }

    /**
     * Every connection service id except the one the default connection points at.
     *
     * **The default is exempt by IDENTITY, not by name**, and the difference is load-bearing: this returns the
     * id that the default-connection parameter actually selects, so renaming connections cannot smuggle a
     * privileged one into the exempt slot. What this pass deliberately does NOT assert is that the default
     * connection carries the RESTRICTED credential — swapping its `url:` to the privileged DSN would leave
     * every alias legitimately pointing at a privileged role. That is a property of configuration rather than
     * of the container graph, and it is asserted by `scripts/gates/no-owner-connection-in-application.php`,
     * which reads `doctrine.yaml` directly. Neither check subsumes the other; both are needed.
     *
     * @return list<string>
     */
    private function privilegedConnectionIds(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::CONNECTIONS_PARAMETER)
            || !$container->hasParameter(self::DEFAULT_CONNECTION_PARAMETER)
        ) {
            throw new \LogicException(\sprintf(
                'Cannot decide which Doctrine connections are privileged: the container publishes no "%s" '
                . 'and/or "%s" parameter. This pass refuses to run rather than removing nothing, because a '
                . 'pass that silently did nothing is indistinguishable from one that had nothing to do.',
                self::CONNECTIONS_PARAMETER,
                self::DEFAULT_CONNECTION_PARAMETER,
            ));
        }

        /** @var array<string, string> $connections */
        $connections = $container->getParameter(self::CONNECTIONS_PARAMETER);
        /** @var string $defaultName */
        $defaultName = $container->getParameter(self::DEFAULT_CONNECTION_PARAMETER);

        if (!\array_key_exists($defaultName, $connections)) {
            throw new \LogicException(\sprintf(
                'The default Doctrine connection is named "%s", which is not one of the configured '
                . 'connections (%s). Refusing to guess which connection is the unprivileged one.',
                $defaultName,
                implode(', ', array_keys($connections)),
            ));
        }

        $defaultId = $connections[$defaultName];

        return array_values(array_filter(
            $connections,
            static fn(string $id): bool => $id !== $defaultId,
        ));
    }

    /**
     * Follows an alias chain to the definition it ultimately names.
     *
     * An alias pointing at an alias pointing at a privileged connection is the same hole one indirection down,
     * and `getAliases()` reports each link separately. Bounded by the aliases already seen so a cycle — which
     * Symfony rejects later, but which must not hang this pass first — terminates.
     */
    private function resolveTarget(ContainerBuilder $container, string $id): string
    {
        $seen = [];

        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }
}
