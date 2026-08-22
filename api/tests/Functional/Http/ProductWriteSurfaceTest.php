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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twes\Domain\Product\Product;

/**
 * THE PRODUCT WRITE SURFACE THROUGH A REAL KERNEL — deserialization, validation, and the published contract.
 *
 * {@see ProductProcessorTest} drives the processor and provider directly, which is right for the response shape
 * and wrong for everything here: whether a JSON number is refused for a decimal field, whether the
 * exactly-one-price-field callback actually RUNS, and whether `PUT` is genuinely absent from the route table
 * are all properties of the framework's behaviour against our declarations. Each would pass a hand-built test
 * while being broken in production.
 *
 * **NO DATABASE IS NEEDED and no case here reaches one**: every refusal happens before the processor runs. So
 * this class stays green on a checkout with no PostgreSQL, and a failure here is never "the cluster is down".
 *
 * **WHAT IS THEREFORE NOT COVERED, stated rather than left to be discovered:** no case asserts a `201` with a
 * body, because reaching the processor with a valid payload opens a transaction and needs a database and a
 * bound tenant. The response SHAPE is pinned in `ProductProcessorTest` against fakes, and the round trip
 * through real `NUMERIC` columns in `DoctrineProductRepositoryTest`.
 */
final class ProductWriteSurfaceTest extends WebTestCase
{
    /**
     * **A JSON NUMBER FOR A MONETARY OR RATE FIELD IS REFUSED, and this is the most important case in the file.**
     *
     * JSON has one number type and it is a double: `0.1` is not representable, so `0.100 TND` — Tunisia's stamp
     * duty, exactly 100 millimes — stops being exact. The rate is worse: F4's worked example needs SEVEN decimal
     * places to express one millime of profit on a ten-thousand-dinar cost, and a double rounds it away
     * silently. The DTOs declare `string`, and this asserts the declaration is ENFORCED rather than coerced —
     * which is not obvious, because the Serializer does coerce numbers to strings for some formats.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('numericFieldsSentAsJsonNumbers')]
    public function testAJsonNumberIsRefusedRatherThanCoerced(string $field, array $body): void
    {
        $response = self::post('/api/products', $body);

        self::assertSame(422, $response['status'], \sprintf('%s must be a decimal STRING on the wire', $field));
        self::assertStringContainsString(
            $field,
            $response['body'],
            'the refusal must name the offending field — a client cannot fix an error that does not say where',
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function numericFieldsSentAsJsonNumbers(): iterable
    {
        yield 'cost' => ['cost', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => 100.0, 'vatRate' => '19', 'profitRate' => '30',
        ]];

        yield 'profitRate' => ['profitRate', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19',
            'profitRate' => 30.0,
        ]];

        yield 'netPrice' => ['netPrice', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19',
            'netPrice' => 130.0,
        ]];

        yield 'vatRate' => ['vatRate', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => 19.0,
            'profitRate' => '30',
        ]];
    }

    /**
     * **THE EXACTLY-ONE-PRICE-FIELD CALLBACK REALLY RUNS, and names BOTH fields.**
     *
     * This is the F4 ruling reaching the wire: a product is priced by a typed rate OR a typed price, never both.
     * A class-level `Assert\Callback` is the kind of constraint that silently does not run if the attribute is
     * misplaced — the vacuous-control shape `CLAUDE.md` § Gotchas records four separate times — and the only way
     * to know it fires is to send a payload that offends it through the real validator.
     *
     * Both field paths are asserted because either one is the one to remove, and a violation with no path
     * leaves a form unable to highlight anything.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('ambiguousPricings')]
    public function testExactlyOnePriceFieldIsRequired(string $why, array $body): void
    {
        $response = self::post('/api/products', $body);

        self::assertSame(422, $response['status'], $why);
        self::assertStringContainsString('profitRate', $response['body'], $why);
        self::assertStringContainsString('netPrice', $response['body'], $why);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function ambiguousPricings(): iterable
    {
        yield 'both' => ['sending both is refused rather than merged', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19',
            'profitRate' => '30', 'netPrice' => '130.000',
        ]];

        yield 'neither' => ['a product with a cost and no price is not priced', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19',
        ]];
    }

    /** A decimal with a comma is well-formed JSON, well-formed as a string, and not a decimal. */
    public function testADecimalWithACommaIsRefused(): void
    {
        $response = self::post('/api/products', [
            'name' => 'Café moulu', 'currency' => 'TND', 'cost' => '100,000', 'vatRate' => '19',
            'profitRate' => '30',
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('cost', $response['body']);
    }

    /**
     * A whitespace-only name is a 422, not a 500 — `Assert\NotBlank` does not trim by default, and
     * `CLAUDE.md` § Gotchas 2026-08-22 records that gap producing a 500 on the client surface.
     */
    public function testAWhitespaceOnlyNameIsA422RatherThanA500(): void
    {
        $response = self::post('/api/products', [
            'name' => '   ', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19', 'profitRate' => '30',
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('name', $response['body']);
    }

    /**
     * **A NEGATIVE PROFIT RATE IS ACCEPTED, and that is a ruling rather than an oversight.**
     *
     * F4: selling below cost is real — clearance, a loss-leader — and must be SURFACED rather than clamped to
     * zero. A validator that refused it would make a legitimate product unrepresentable, so this case asserts
     * the payload gets past validation. It cannot assert a 201 without a database, so it asserts the negative:
     * whatever happens next, it is not a validation refusal naming the rate.
     */
    public function testANegativeProfitRateIsNotAValidationError(): void
    {
        $response = self::post('/api/products', [
            'name' => 'Clearance item', 'currency' => 'TND', 'cost' => '100.000', 'vatRate' => '19',
            'profitRate' => '-20',
        ]);

        self::assertStringNotContainsString(
            'profitRate',
            $response['body'],
            'a negative rate is legitimate — clearance and loss-leaders are real, and F4 refuses to clamp them',
        );
    }

    /**
     * THE TWO ROUTES AND THEIR METHODS, pinned because each one is a breaking change to move.
     *
     * `PUT`, `PATCH` and `DELETE` must NOT exist, and their absence is argued on {@see ProductResource}: an edit
     * endpoint has to answer what an edit does to AUTHORSHIP, which is a tax-adjacent rule F4 already settled in
     * the domain and which has no HTTP contract yet.
     */
    public function testTheProductRoutesExistAndTheForbiddenVerbsDoNot(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $methodsByPath = [];

        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();

            if (str_starts_with($path, '/api/products')) {
                $methodsByPath[$path] = array_merge($methodsByPath[$path] ?? [], $route->getMethods());
            }
        }

        self::assertSame(['POST'], $methodsByPath['/api/products'] ?? [], 'create');
        self::assertSame(['GET'], $methodsByPath['/api/products/{id}'] ?? [], 'read only');

        // SORTED, because the order the router registers routes in is the framework's business and not our
        // contract's — asserting the unsorted list pins something we never decided.
        $paths = array_keys($methodsByPath);
        sort($paths);

        self::assertSame(
            ['/api/products', '/api/products/{id}'],
            $paths,
            'no other product route exists — a collection GET in particular is deliberately absent',
        );
    }

    /** A domain bound reaches the published contract, so a client learns it from the schema rather than a 422. */
    public function testALengthBoundReachesThePublishedContract(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        self::assertSame(
            Product::MAX_SKU_LENGTH,
            self::schemaNamed($schemas, 'NewProductInput')['properties']['sku']['maxLength'] ?? null,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private static function schemaNamed(array $schemas, string $name): array
    {
        // MATCHED BY SUFFIX: API Platform namespaces an operation-scoped input schema as
        // `Product.NewProductInput`, and which form appears is the framework's business rather than our
        // contract's.
        foreach ($schemas as $key => $schema) {
            if ($key === $name || str_ends_with($key, '.' . $name)) {
                return $schema;
            }
        }

        self::fail(\sprintf(
            'No schema named %s in the OpenAPI document; found: %s',
            $name,
            implode(', ', array_keys($schemas)),
        ));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: string}
     */
    private static function post(string $uri, array $body): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/ld+json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => (string) $client->getResponse()->getContent(),
        ];
    }
}
