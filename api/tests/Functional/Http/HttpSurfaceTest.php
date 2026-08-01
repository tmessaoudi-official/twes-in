<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twes\Domain\Money\Currency;

/**
 * THE FIRST CONTENT OF THE `functional` SUITE — HTTP through the kernel.
 *
 * `phpunit.xml` has declared this suite since Wave 0 and `CLAUDE.md` recorded it as empty "until there is an HTTP
 * surface to exercise". There is one now, so this is that suite starting.
 *
 * **What these tests are FOR, beyond smoke.** `CLAUDE.md` § "The API contract is ours to design" says the contract
 * is load-bearing because a shipped mobile client updates on app-store timelines, so a field name, an enum value,
 * an envelope shape or a status code is a BREAKING CHANGE with a migration plan rather than an incidental edit.
 * That rule needs something that fails when the contract moves — otherwise it is a paragraph. These assertions are
 * that something, and they are deliberately written against the WIRE (paths, status codes, JSON keys) rather than
 * against PHP objects, because the wire is what the clients see.
 */
final class HttpSurfaceTest extends WebTestCase
{
    /**
     * LIVENESS must not depend on anything but the process.
     *
     * The container's orchestrator RESTARTS on a failed liveness probe and merely stops routing on a failed
     * readiness probe. If liveness touched the database, a brief outage would become a restart storm across every
     * replica — the outage amplified by the thing meant to detect it. So this asserts the endpoint answers, and
     * `HealthController` asserts it by touching nothing.
     */
    public function testLivenessAnswersWithoutTouchingAnythingExternal(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"alive"}', (string) $client->getResponse()->getContent());
    }

    /**
     * READINESS reports each check by name, and reports 503 rather than 200 when any fails.
     *
     * Asserted as a STRUCTURE rather than a fixed body, because the checks will grow — a queue, a cache, the
     * document renderer. What must not change is that a failing check yields 503 and names itself: an orchestrator
     * reads the status, a human reads the names.
     */
    public function testReadinessNamesEveryCheckAndFailsClosed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/ready');

        /** @var array{status: string, checks: array<string, bool>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('checks', $body);
        self::assertArrayHasKey('database', $body['checks']);
        self::assertArrayHasKey('schema', $body['checks']);
        // The tenant-binding check is the one that is easy to dismiss as paranoia and is not: FrankenPHP runs the
        // app in a persistent worker, so a connection is REUSED across requests, and a tenant left bound on it is a
        // cross-tenant read for whoever gets it next.
        self::assertArrayHasKey('tenant_binding_clean', $body['checks']);

        $expected = \in_array(false, $body['checks'], true) ? 503 : 200;
        self::assertSame($expected, $client->getResponse()->getStatusCode(), \sprintf(
            "Readiness must fail CLOSED: any false check means 503.\n  body: %s",
            (string) $client->getResponse()->getContent(),
        ));
        self::assertSame(200 === $expected ? 'ready' : 'not_ready', $body['status']);
    }

    /**
     * THE CONTRACT ASSERTION THAT MATTERS MOST: TND's scale is 3, on the wire.
     *
     * `CLAUDE.md` is emphatic that the default currency has THREE decimal places, so a 2-decimal assumption is a
     * bug for the default currency rather than an edge case. Every client formats and validates money, and the only
     * alternative to serving the scale is three clients each hardcoding a table — three independent places for the
     * same wrong number. This endpoint exists so that cannot happen, and this assertion is what keeps it honest.
     */
    public function testTheCurrencyCollectionServesEachCurrencysRealScale(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/currencies', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json; charset=utf-8');

        /** @var list<array{code: string, scale: int}> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $scales = array_column($body, 'scale', 'code');

        self::assertSame(3, $scales['TND'] ?? null, 'TND has THREE decimal places — 1 dinar = 1000 millimes.');
        self::assertSame(2, $scales['EUR'] ?? null);
        self::assertSame(0, $scales['JPY'] ?? null, 'JPY has no minor unit at all.');

        // EVERY registered currency is served, compared against the domain rather than a written number: a count in
        // a test drifts exactly like a count in prose.
        self::assertSame(
            [],
            array_diff(Currency::all(), array_keys($scales)),
            'Every currency the domain accepts must be discoverable over HTTP, or a client cannot format it.',
        );
    }

    public function testASingleCurrencyIsAddressableByItsCode(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/currencies/TND', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"code":"TND","scale":3}',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * An unknown code is 404, not 500.
     *
     * The domain throws `UnknownCurrency`, and letting it escape would render a 500 for a client typo.
     * `CLAUDE.md` § "Translation keys" draws exactly this line: our own faults map to `error.internal`, while a
     * mistake the user can fix gets an answer they can act on. A wrong currency code is the second kind.
     */
    public function testAnUnknownCurrencyCodeIsNotFoundRatherThanAServerError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/currencies/ZZZ', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The OpenAPI document is served, and carries OUR licence.
     *
     * `completeness-reviewer` treats an API change that does not reach the OpenAPI document as a P0, so the
     * document has to exist and be reachable before that rule can bind. The licence assertion is not decoration:
     * this project is dual-licensed and `LICENSING.md` § Notices requires the notice to travel with the artefact.
     */
    public function testTheOpenApiDocumentIsServedAndCarriesTheDualLicence(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful();

        /** @var array{openapi: string, info: array{title: string, license: array{name: string}}} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('3.', $document['openapi']);
        self::assertSame('twes-in API', $document['info']['title']);
        self::assertStringContainsString('AGPL-3.0-or-later', $document['info']['license']['name']);
    }

    /**
     * JSON-LD is the default representation, and the collection is NOT paginated.
     *
     * Both are deliberate contract decisions recorded in `config/packages/api_platform.yaml`. The
     * no-pagination-here choice is the documented exception rather than a precedent: the currency registry is a
     * closed set a client needs in full to format anything, so paging it would make every client page through it
     * on startup to reassemble a list we could send once. Pagination stays ON for collections that grow.
     */
    public function testTheDefaultRepresentationIsJsonLdAndTheRegistryIsServedWhole(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/currencies');

        self::assertResponseIsSuccessful();

        /** @var array{'@context': string, '@type': string, totalItems: int, member: list<array<string, mixed>>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('@context', $body);
        self::assertSame(\count(Currency::all()), $body['totalItems']);
        self::assertCount(
            $body['totalItems'],
            $body['member'],
            'The registry is served WHOLE. A paginated page here would be silently short, and a client would '
            . 'format amounts with a currency table missing its tail.',
        );
    }

    public function testTheApiEntrypointIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api');

        self::assertResponseIsSuccessful();
    }

    /**
     * A stateless API sets no session cookie, on any endpoint.
     *
     * `framework.yaml` disables sessions and `api_platform.yaml` marks every operation stateless — this asserts the
     * consequence rather than the configuration. It also protects worker mode: state that survives a response is
     * exactly what must not exist when the process is reused.
     */
    public function testNoEndpointSetsASessionCookie(): void
    {
        $client = static::createClient();

        foreach (['/health', '/health/ready', '/api', '/api/currencies', '/api/currencies/TND'] as $path) {
            $client->request('GET', $path);

            self::assertSame([], $client->getResponse()->headers->getCookies(), \sprintf(
                '%s set a cookie. A stateless API must not acquire server state, and under FrankenPHP worker mode '
                . 'state that survives a response is state the next request inherits.',
                $path,
            ));
        }
    }
}
