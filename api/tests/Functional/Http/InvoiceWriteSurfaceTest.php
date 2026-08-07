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
use Twes\Domain\Document\FixedCharge;

/**
 * THE WRITE SURFACE THROUGH A REAL KERNEL — deserialization, validation, and the published contract.
 *
 * `InvoiceWriteProcessorTest` drives the processors directly, which is right for the status-code mapping and wrong for
 * everything here: **nothing below can be observed without the real serializer and the real validator.** Whether a
 * JSON number is refused for a decimal field, whether `#[Assert\Valid]` actually cascades into the nested DTOs,
 * whether the OpenAPI document types the request body — each of those is a property of the framework's behaviour
 * against our declarations, and each would pass a hand-built test while being broken in production.
 *
 * **NO DATABASE IS NEEDED and no case here reaches one, which is deliberate rather than a limitation.** Every refusal
 * below happens before the processor runs, and the one case with a valid body asserts the tenancy boundary, which is
 * checked before any query is issued. So this class stays green on a checkout with no PostgreSQL, and a failure here
 * is never "the cluster is down" — which `CLAUDE.md` § Gotchas records as the misdiagnosis a red integration suite
 * invites.
 */
final class InvoiceWriteSurfaceTest extends WebTestCase
{
    /**
     * **A JSON NUMBER FOR A MONETARY OR QUANTITY FIELD IS REFUSED.** The single most important case in this file.
     *
     * JSON has one number type and it is a double: `0.1` is not representable in one, so a client that sends
     * `"unitNet": 19.99` has already lost the guarantee `Money` exists to provide, and `0.100 TND` — exactly 100
     * millimes, Tunisia's stamp duty — stops being exact. `CLAUDE.md` records money-is-never-a-float as
     * unfixable-later and names upstream's `double` columns as the worst defect in the product twes-in learns from.
     *
     * The DTOs declare `string`, and this asserts that the declaration is ENFORCED rather than coerced. That is not
     * obvious: the Serializer *does* coerce numbers to strings for some formats, so "it is typed `string`" is not by
     * itself an answer.
     *
     * **AND IT NAMES THE FIELD, which took a change to the operation to achieve.** Without
     * `COLLECT_DENORMALIZATION_ERRORS` the answer was `400 {"detail":"The input data is misformatted."}` — a client
     * told its payload was wrong and not where. [Verified: that was the body before the flag.] With it the answer is a
     * 422 whose `propertyPath` is `lines[0].unitNet`, which is what this pins.
     */
    public function testAJsonNumberForAnAmountIsRefusedRatherThanCoerced(): void
    {
        $response = self::post('/api/invoices', [
            'currency' => 'TND',
            // A JSON NUMBER, not a string. This is the defect.
            'lines' => [['quantity' => '1', 'unitNet' => 19.99, 'vatRate' => '19']],
        ]);

        self::assertSame(422, $response['status'], 'a JSON number for an amount must be refused, and it is the CALLER\'s error');
        self::assertStringContainsString(
            'lines[0].unitNet',
            $response['body'],
            'the refusal must name the offending field BY PATH — a client cannot fix an error that does not say where '
            . 'it is, and "the input data is misformatted" is what this endpoint answered before the operation '
            . 'collected denormalization errors',
        );
    }

    /** The same for a quantity, because a fractional quantity is the field a client is most tempted to send as one. */
    public function testAJsonNumberForAQuantityIsRefusedToo(): void
    {
        $response = self::post('/api/invoices', [
            'currency' => 'TND',
            'lines' => [['quantity' => 2.5, 'unitNet' => '1.000', 'vatRate' => '19']],
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('lines[0].quantity', $response['body']);
    }

    /**
     * **`#[Assert\Valid]` REALLY CASCADES INTO THE NESTED LINES**, which is the thing that would silently not work.
     *
     * Without it — or without the docblock element types that let the Serializer build real `NewInvoiceLineInput`
     * objects at all — the collection is checked for its own constraints and its elements are not: a validator
     * reporting clean on invalid input, which is the shape `CLAUDE.md` § Gotchas records four times as *a control that
     * silently does not run*.
     *
     * `1,5` is a decimal with a comma. It is well-formed JSON, well-formed as a string, and not a decimal — so only
     * the constraint on the nested DTO can catch it.
     */
    public function testValidationCascadesIntoTheNestedLines(): void
    {
        $response = self::post('/api/invoices', [
            'currency' => 'TND',
            'lines' => [['quantity' => '1,5', 'unitNet' => '1.000', 'vatRate' => '19']],
        ]);

        self::assertSame(422, $response['status'], 'a constraint violation on a nested DTO is a 422');
        self::assertStringContainsString(
            'lines[0].quantity',
            $response['body'],
            'and the violation must carry the PATH into the collection, or a client cannot tell which line is wrong',
        );
    }

    /** An empty `lines` array is refused: an invoice with no lines can never be issued, so creating one is pointless. */
    public function testAnInvoiceWithNoLinesIsRefused(): void
    {
        $response = self::post('/api/invoices', ['currency' => 'TND', 'lines' => []]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('lines', $response['body']);
    }

    /** A currency that is not three uppercase letters is a validation failure naming the field, not a domain error. */
    public function testAMalformedCurrencyIsAValidationFailure(): void
    {
        $response = self::post('/api/invoices', [
            'currency' => 'euro',
            'lines' => [['quantity' => '1', 'unitNet' => '1.00', 'vatRate' => '19']],
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('currency', $response['body']);
    }

    /**
     * A MISSING REQUIRED FIELD IS REFUSED BY THE CONSTRUCTOR, which is why the DTOs have no defaults.
     *
     * The error names the parameter, which is a better message than a validator's "this value should not be blank" on a
     * field the client never sent at all — and it is why `NewInvoiceLineInput` puts required-ness in its constructor
     * rather than in a constraint.
     */
    public function testAMissingRequiredFieldNamesTheParameter(): void
    {
        $response = self::post('/api/invoices', [
            'currency' => 'TND',
            'lines' => [['quantity' => '1', 'unitNet' => '1.000']],
        ]);

        self::assertGreaterThanOrEqual(400, $response['status']);
        self::assertLessThan(500, $response['status']);
        self::assertStringContainsString('vatRate', $response['body']);
    }

    /**
     * **A WELL-FORMED WRITE WITH NO TENANT BOUND IS REFUSED — Wave 1's boundary rule, over HTTP.**
     *
     * *No tenant-less path may hydrate an aggregate.* This is the only case here that gets as far as the use case, and
     * it asserts the refusal at its source rather than through a status code, for two reasons. The status is Wave 7's
     * business — it becomes a 401 when authentication lands, and pinning 500 now would pin a placeholder. And
     * asserting "some 4xx or 5xx" would pass if the database were merely down, which is a test passing for the wrong
     * reason.
     *
     * `catchExceptions(false)` makes the assertion exact, and it costs nothing: the refusal happens before any query is
     * issued, so no cluster is involved either way.
     */
    public function testAWellFormedWriteWithNoTenantBoundIsRefusedAtTheBoundary(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('with no tenant bound');

        $client->request(
            'POST',
            '/api/invoices',
            server: ['CONTENT_TYPE' => 'application/ld+json'],
            content: json_encode([
                'currency' => 'TND',
                'lines' => [['quantity' => '1', 'unitNet' => '1.000', 'vatRate' => '19']],
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * THE PUBLISHED CONTRACT DOCUMENTS THE REQUEST BODY WITH TYPED LINE ITEMS.
     *
     * `completeness-reviewer` treats the OpenAPI document as a tier an API change must reach, and this is the property
     * that silently degrades: PHP has no generics, so `array $lines` tells the schema factory nothing. The element
     * types come from the `@param list<NewInvoiceLineInput>` annotation, read by `property_info`'s PhpStan extractor —
     * which is registered only because `phpstan/phpdoc-parser` and `phpdocumentor/type-resolver` are non-dev
     * requirements. Move either back to `require-dev` and this fails while every other case here stays green: the
     * schema would document `lines` as an untyped array, a contract document that is wrong about the contract.
     */
    public function testTheOpenApiDocumentTypesTheRequestBodysLineItems(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful();

        /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        $input = self::schemaNamed($schemas, 'NewInvoiceInput');
        self::assertArrayHasKey('lines', $input['properties'] ?? []);
        self::assertSame(
            '#/components/schemas/NewInvoiceLineInput',
            $input['properties']['lines']['items']['$ref'] ?? null,
            'the request schema must reference the LINE schema — an untyped array here is a wrong contract document',
        );

        // And the nested schema exists with its own fields typed as STRINGS, which is the money-is-never-a-float rule
        // expressed in the document three clients generate from.
        $line = self::schemaNamed($schemas, 'NewInvoiceLineInput');
        foreach (['quantity', 'unitNet', 'vatRate'] as $field) {
            self::assertSame(
                'string',
                $line['properties'][$field]['type'] ?? null,
                \sprintf('%s must be documented as a string, never a number', $field),
            );
        }
    }

    /**
     * A DOMAIN BOUND REACHES THE PUBLISHED CONTRACT, so a client can enforce it before sending — for `Length`.
     *
     * `FixedCharge::MAX_LABEL_LENGTH` is referenced by the constraint rather than repeated, and API Platform turns
     * `Assert\Length` into `maxLength`. Asserted against the CONSTANT rather than a literal, so raising the bound is a
     * one-line change this test follows instead of contradicting.
     *
     * **`Assert\Count` DOES NOT REACH THE SCHEMA, and that is stated rather than left to be discovered.** The first
     * version of this case also asserted `maxItems` for `Invoice::MAX_LINES` and FAILED: the schema for `lines` carries
     * only `type` and `items`. `PropertySchemaCountRestriction` exists in API Platform and maps `Count` to
     * `maxItems`/`minItems`, but it does not survive onto a property whose schema is built as a typed collection
     * [Verified: `debug:container PropertySchemaCountRestriction` finds no service, and the generated schema has
     * neither keyword]. So the line cap IS enforced — by the validator, and again by `Invoice::withLine()` — and is
     * NOT documented. A client discovers it as a 422 rather than from the contract, which is an honest gap and the
     * reason it is named here instead of papered over with a hand-written `openapiContext`: a schema keyword written
     * by hand beside a constraint is two copies of one bound.
     */
    public function testALengthBoundReachesThePublishedContract(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        self::assertSame(
            FixedCharge::MAX_LABEL_LENGTH,
            self::schemaNamed($schemas, 'NewFixedChargeInput')['properties']['label']['maxLength'] ?? null,
        );
    }

    /**
     * THE THREE ROUTES AND THEIR METHODS, pinned because each one is a breaking change to move.
     *
     * `POST /api/invoices/{id}/issue` in particular: a sub-resource operation rather than a field on create, because an
     * irreversible act should not be reachable two ways. And `PUT`, `PATCH` and `DELETE` must NOT exist — an issued
     * document is immutable and is `cancel()`ed rather than deleted, so the refusal belongs in the route table rather
     * than in an error response.
     */
    public function testTheWriteRoutesExistAndTheForbiddenVerbsDoNot(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $methodsByPath = [];

        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();

            if (str_starts_with($path, '/api/invoices')) {
                $methodsByPath[$path] = array_merge($methodsByPath[$path] ?? [], $route->getMethods());
            }
        }

        self::assertSame(['POST'], $methodsByPath['/api/invoices'] ?? [], 'create');
        self::assertSame(['POST'], $methodsByPath['/api/invoices/{id}/issue'] ?? [], 'the issue transition');
        self::assertSame(['GET'], $methodsByPath['/api/invoices/{id}'] ?? [], 'read only — no PUT, PATCH or DELETE');
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private static function schemaNamed(array $schemas, string $name): array
    {
        // MATCHED BY SUFFIX, not by an exact key. API Platform namespaces an operation-scoped input schema as
        // `Invoice.NewInvoiceInput` while the nested ones keep their bare class names, and which form appears is the
        // framework's business rather than our contract's. Anchored at the end so `NewInvoiceInput` cannot match
        // `Invoice.NewInvoiceInput` in the wrong direction and pick a schema for a different class.
        foreach ($schemas as $key => $schema) {
            if ($key === $name || str_ends_with($key, '.' . $name)) {
                return $schema;
            }
        }

        self::fail(\sprintf('No schema named %s in the OpenAPI document; found: %s', $name, implode(', ', array_keys($schemas))));
    }

    /**
     * POST a JSON body and return the status and the raw body.
     *
     * `application/ld+json` because that is the default format an API Platform client negotiates, and the one whose
     * error envelope (RFC 9457 problem details) the contract commits to.
     *
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
