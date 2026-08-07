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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingDriver;
use Twes\Infrastructure\Tenancy\Doctrine\TenantBindingDriver;
use Twes\Infrastructure\Tenancy\Doctrine\TenantBindingMiddleware;

/**
 * THE CONTAINER HALF: is the binding middleware actually INSTALLED, and on which connection?
 *
 * `TenantBindingMiddlewareTest` proves what the middleware DOES, against a real policy and the restricted runtime
 * role. It builds its own DBAL connection, so it is structurally blind to the question this class asks — and that
 * blindness is exactly the shape of the defect being repaired: `bind()` behaved correctly in every test that called
 * it and had no production call site at all. **A behaviour test and a wiring test are not substitutes for each
 * other, and the gap between them is where this defect lived for three commits.**
 *
 * The second assertion is the one that is easy to get wrong. `owner` exists so migrations run with a credential that
 * is not the application's; a migration is legitimately tenant-less and runs before any tenant exists, so binding
 * there would guard nothing and could only fail. `config/services.yaml` therefore tags
 * {@see TenantBindingMiddleware} with `connection: default`, because DoctrineBundle autoconfigures
 * `Doctrine\DBAL\Driver\Middleware` with a BARE `doctrine.middleware` tag, and a bare tag really does mean EVERY
 * connection — so removing the scoped tag installs tenant binding on the migration connection.
 *
 * **The neighbouring `autoconfigure: false` is NOT what does that, and writing this class is what proved it.** The
 * first version of this docblock repeated the claim in `services.yaml` — that leaving autoconfiguration on while
 * adding a scoped tag yields both registrations — and the mutant for it SURVIVED. `MiddlewaresPass` skips every tag
 * with no `connection` attribute when building its map (`continue` at MiddlewaresPass.php:38-44), so the bare tag
 * contributes nothing and the scoped one stands alone. Both the comment and this docblock were corrected in place;
 * the flag is kept for a cosmetic reason stated there. The lesson is `CLAUDE.md`'s own: a paragraph explaining why
 * something must be so is the highest-value thing in this repository to spend ten minutes disproving.
 *
 * No `#[CoversClass]`: the subject is a container configuration file rather than a class, and naming the driver here
 * would claim coverage of behaviour this class deliberately does not exercise.
 */
#[CoversNothing]
final class TenantBindingWiringTest extends WebTestCase
{
    /**
     * THE `default` CONNECTION — the one every repository, handler and provider is given — is wrapped.
     *
     * This is the assertion that goes red if the tag is removed, which is the production state at the defective
     * commit. Note that it does NOT connect: `getDriver()` walks the decoration chain the container built, so the
     * wiring is observable with no database at all, which is why this belongs in `functional` rather than
     * `integration`.
     */
    public function testTheApplicationConnectionIsWrappedByTheBindingDriver(): void
    {
        self::assertTrue(
            self::driverChainOf('default')[TenantBindingDriver::class] ?? false,
            'The default connection must be wrapped by TenantBindingDriver, or nothing writes twes.tenant_id and '
            . 'every tenant-owned read returns nothing while every write is refused by row-level security. '
            . 'This case fires only when the middleware is not a SERVICE AT ALL — deleting its entry from '
            . 'config/services.yaml is not enough, because the Twes\Infrastructure\ resource import then '
            . 'autowires it anyway with DoctrineBundle\'s bare tag. [Measured: deleting the entry alone turns the '
            . 'OWNER case red instead of this one.] So look for an added `exclude:` line, or a moved file.',
        );
    }

    /**
     * THE `owner` CONNECTION IS NOT — the direction a bare autoconfigured tag would break silently.
     *
     * Migrations run on this connection, tenant-less and before any tenant row exists. A binding middleware here
     * would open every migration transaction and then try to write a setting for a tenant that is not there; the
     * early return makes that harmless today, which is precisely why nothing else would report it.
     */
    public function testTheMigrationConnectionIsNotWrapped(): void
    {
        $chain = self::driverChainOf('owner');

        self::assertArrayNotHasKey(
            TenantBindingDriver::class,
            $chain,
            'The owner connection must NOT be wrapped: it is the migration credential, tenant-less by design. '
            // THE LIKELY CAUSE FIRST, AND `autoconfigure: false` IS NOT IT — this message said it was, which made
            // it a FOURTH site of the claim `services.yaml`, `TenantBindingMiddleware` and CLAUDE.md were amended
            // to retract, in the one place a developer reads while something is broken. [Measured: dropping only
            // `autoconfigure: false` leaves this case GREEN; deleting the whole entry turns it red, because the
            // resource import then autowires the middleware with a bare, unscoped tag.]
            . 'The likely cause is that the `{ name: doctrine.middleware, connection: default }` tag was removed or '
            . 'its `connection` attribute lost, which makes DoctrineBundle\'s bare autoconfigured tag the only one '
            . 'left — and a bare tag means EVERY connection. It is NOT `autoconfigure: false`, which is cosmetic: '
            . 'MiddlewaresPass skips every doctrine.middleware tag carrying no `connection`, so the bare tag and '
            . 'the scoped one never compete. Chain: ' . implode(', ', array_keys($chain)),
        );
    }

    /**
     * THE SAVEPOINT GUARD IS STILL THERE TOO, on `default` and only there.
     *
     * Included because the two middlewares are registered by adjacent stanzas in the same file and share the same
     * two collaborators, so a copy-paste edit to one is the likeliest way to lose the other. Asserted in the class
     * that already walks the chain rather than in a third one.
     */
    public function testTheSavepointGuardIsStillInstalledOnTheApplicationConnectionAlone(): void
    {
        self::assertArrayHasKey(SavepointTenantBindingDriver::class, self::driverChainOf('default'));
        self::assertArrayNotHasKey(SavepointTenantBindingDriver::class, self::driverChainOf('owner'));
    }

    /**
     * **THE ISSUE OPERATION DECLARES ITS 404 AND ITS 422 IN THE EXPORTED SCHEMA.**
     *
     * Asserted against the OpenAPI factory's own output rather than against the attribute, because a generated client
     * is written from the exported document and that is the artefact that was wrong: the processor answers 404 for a
     * document that is absent or belongs to another tenant, and 422 for a domain refusal — a second issue of the same
     * document, or one with no lines — and neither status appeared, so a generated client had no branch for the two
     * outcomes a caller is most likely to hit. A double-click and a stale page.
     *
     * In this class rather than a new one because it needs the same booted kernel and nothing else. The mutant is
     * direct: delete the `openapi:` argument from the operation and this goes red.
     *
     * **A STALE `var/cache/test` USED TO MAKE THIS FAIL AGAINST CORRECT CODE — and noticing only that direction is how
     * a P0 survived.** The first run reported `404` absent while the same factory booted by hand returned
     * `[200, 404, 422, 400]`; clearing the cache fixed it, and this docblock recorded it as a debugging tip and
     * stopped there. It is the FALSE-POSITIVE direction that mattered: with `APP_DEBUG=0` a kernel performs no
     * freshness check, so deleting `TenantBindingMiddleware`'s registration left the whole 949-test suite GREEN on any
     * machine with a warm cache. The second MAXIMAL round filed that as a P0.
     *
     * `tests/bootstrap.php` now discards `var/cache/test` once per run, so neither direction can occur and this note
     * is history rather than advice. Kept because the lesson is not about caches: I met the symptom, explained it,
     * wrote the explanation down, and never asked what the same mechanism does when it hides a failure instead of
     * inventing one.
     */
    public function testTheIssueOperationDeclaresItsFailureResponses(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('api_platform.openapi.factory');
        self::assertInstanceOf(\ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface::class, $factory);

        $paths = $factory()->getPaths()->getPath('/api/invoices/{id}/issue');
        self::assertNotNull($paths, 'the issue operation must be in the exported schema at all');

        $post = $paths->getPost();
        self::assertNotNull($post);

        foreach (['404', '422'] as $status) {
            self::assertArrayHasKey(
                $status,
                $post->getResponses() ?? [],
                $status . ' is an answer this operation really gives, so a client generated from the schema needs it',
            );
        }
    }

    /**
     * Every class in a connection's driver decoration chain, innermost last, as a set.
     *
     * A set rather than a list because order is DBAL's business and asserting it would pin a detail this project has
     * no opinion on. Walked through `AbstractDriverMiddleware`'s own `$wrappedDriver`, by reflection because DBAL
     * exposes no accessor for it — the alternative is to connect and infer the chain from behaviour, which is what
     * `TenantBindingMiddlewareTest` does and what this class exists NOT to duplicate.
     *
     * @return array<class-string, true>
     */
    private static function driverChainOf(string $name): array
    {
        self::bootKernel();
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $connection = $registry->getConnection($name);
        self::assertInstanceOf(Connection::class, $connection);

        $chain = [];
        $driver = $connection->getDriver();

        while (true) {
            $chain[$driver::class] = true;

            if (!$driver instanceof AbstractDriverMiddleware) {
                return $chain;
            }

            $wrapped = new \ReflectionProperty(AbstractDriverMiddleware::class, 'wrappedDriver')->getValue($driver);
            self::assertInstanceOf(Driver::class, $wrapped);
            $driver = $wrapped;
        }
    }
}
