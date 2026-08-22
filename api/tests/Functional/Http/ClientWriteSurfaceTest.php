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
use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;

/**
 * THE CLIENT WRITE SURFACE THROUGH A REAL KERNEL — deserialization, validation, and the published contract.
 *
 * {@see ClientProcessorTest} drives the processor and the provider directly, which is right for the status-code
 * mapping and the response shape, and wrong for everything here: **nothing below can be observed without the real
 * serializer, the real validator and the real router.** Whether `#[Assert\Valid]` actually cascades into the nested
 * contact and address DTOs, whether the OpenAPI document types the request body, whether `PUT` is genuinely absent
 * from the route table rather than merely unimplemented — each is a property of the framework's behaviour against
 * our declarations, and each would pass a hand-built test while being broken in production.
 *
 * **NO DATABASE IS NEEDED AND NO CASE HERE REACHES ONE**, deliberately rather than as a limitation: every refusal
 * below happens before the processor runs. So this class stays green on a checkout with no PostgreSQL, and a
 * failure here is never "the cluster is down" — the misdiagnosis `CLAUDE.md` § Gotchas records a red integration
 * suite as inviting.
 *
 * **WHAT IS THEREFORE NOT COVERED, stated rather than left to be discovered:** no case here asserts a `201` with a
 * body, because reaching the processor with a valid payload opens a transaction and needs a database and a bound
 * tenant. The response SHAPE is pinned in {@see ClientProcessorTest} against fakes and the round trip through real
 * columns in `DoctrineClientRepositoryTest`; what no test yet exercises is those two joined by a live HTTP request.
 */
final class ClientWriteSurfaceTest extends WebTestCase
{
    /**
     * **`#[Assert\Valid]` REALLY CASCADES INTO THE NESTED CONTACTS**, which is the thing that would silently not
     * work.
     *
     * Without it — or without the docblock element type that lets the Serializer build real `NewContactInput`
     * objects at all — the collection is checked for its own constraints and its elements are not: a validator
     * reporting clean on invalid input, which is the shape `CLAUDE.md` § Gotchas records four separate times as *a
     * control that silently does not run*.
     *
     * **AND THE VIOLATION MUST CARRY THE PATH INTO THE COLLECTION.** A client sending twenty contacts cannot act on
     * "an e-mail address is invalid"; it can act on `contacts[1].email`.
     */
    public function testValidationCascadesIntoTheNestedContacts(): void
    {
        $response = self::post('/api/clients', [
            'name' => 'Acme',
            'contacts' => [
                ['name' => 'Amine', 'email' => 'amine@example.test'],
                ['name' => 'Yasmine', 'email' => 'not-an-address'],
            ],
        ]);

        self::assertSame(422, $response['status'], 'a constraint violation on a nested DTO is a 422');
        self::assertStringContainsString(
            'contacts[1].email',
            $response['body'],
            'the refusal must name WHICH contact is wrong, or a client with twenty of them cannot fix anything',
        );
    }

    /** The same cascade into the address object, which is a single nested DTO rather than a collection. */
    public function testValidationCascadesIntoTheAddress(): void
    {
        $response = self::post('/api/clients', [
            'name' => 'Acme',
            // LOWERCASE, which the domain refuses rather than upcasing. The edge constraint is what turns that into
            // a 422 naming the field instead of a domain exception the processor has to translate.
            'address' => ['line1' => '12 Rue de la Paix', 'city' => 'Paris', 'countryCode' => 'fr'],
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('address.countryCode', $response['body']);
    }

    /** A name is the one required field, because it is what an invoice is addressed to. */
    public function testAClientWithNoNameIsRefused(): void
    {
        $response = self::post('/api/clients', ['name' => '']);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('name', $response['body']);
    }

    /**
     * **A WHITESPACE-ONLY VALUE FOR A REQUIRED FIELD IS THE CALLER'S ERROR, and it answered 500 until 2026-08-22.**
     *
     * `Assert\NotBlank` does NOT trim by default, so `"   "` is a non-empty string and passes it. The domain
     * trims and then refuses, and it does so inside `Client::create()` — which {@see CreateClientProcessor} runs
     * OUTSIDE its `try`, deliberately, on the stated grounds that nothing a caller can send reaches the handler.
     * That reasoning was sound and its premise was false for exactly this payload: the validator passed it, the
     * aggregate refused it, and the `\InvalidArgumentException` propagated as a 500 carrying the domain's own
     * English sentence to a client that only needed to be told to type a name. [Verified: `{"name":"   "}`
     * returned `500` with `A client needs a name…` before `normalizer: 'trim'` was added to every `NotBlank`.]
     *
     * This is the shape `CLAUDE.md` § Gotchas records as *a control asserted in prose and enforced nowhere* — the
     * two-halves rule was written in three docblocks and pinned by no case. All four required fields are checked
     * here, not just the one that was found, because the defect was in the CONSTRAINT'S DEFAULT rather than in any
     * one field: every `NotBlank` in the three input DTOs had it.
     *
     * **A DATA PROVIDER RATHER THAN A LOOP, and that is forced rather than stylistic:** `WebTestCase` refuses to
     * boot a second kernel inside one test, so four `createClient()` calls in a `foreach` error out instead of
     * asserting. One case per payload also names the offending field in the failure output.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('blankRequiredFields')]
    public function testAWhitespaceOnlyRequiredFieldIsA422RatherThanA500(string $what, array $body): void
    {
        self::assertSame(
            422,
            self::post('/api/clients', $body)['status'],
            \sprintf('%s is retypeable, so a blank one is the caller\'s error and never ours', $what),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function blankRequiredFields(): iterable
    {
        yield 'the client name' => ['the client name', ['name' => '   ']];

        yield 'a contact name' => ['a contact name', ['name' => 'Acme', 'contacts' => [['name' => "\t "]]]];

        yield 'the first address line' => ['the first address line', [
            'name' => 'Acme',
            'address' => ['line1' => '  ', 'city' => 'Paris', 'countryCode' => 'FR'],
        ]];

        yield 'the city' => ['the city', [
            'name' => 'Acme',
            'address' => ['line1' => '12 Rue de la Paix', 'city' => ' ', 'countryCode' => 'FR'],
        ]];
    }

    /**
     * A JSON number where a string is declared is REFUSED rather than coerced, and the refusal names the field.
     *
     * The type declaration is the enforcement, so asserting the PHP type again would be a dead assertion PHPStan
     * refuses — what is worth pinning is the wire behaviour, and it is not obvious: the Serializer *does* coerce
     * numbers to strings for some formats. Naming the field at all needed `COLLECT_DENORMALIZATION_ERRORS` on the
     * operation; without it the answer is an opaque `400 {"detail":"The input data is misformatted."}`, which is
     * the same defect the invoice write path had to fix to name `lines[0].unitNet`.
     */
    public function testAJsonNumberForANameIsRefusedRatherThanCoerced(): void
    {
        $response = self::post('/api/clients', ['name' => 12345]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('name', $response['body']);
    }

    /** The contact cap is checked at the edge, so an oversized payload is one message rather than a late refusal. */
    public function testMoreContactsThanTheAggregateAllowsIsRefused(): void
    {
        $contacts = array_fill(0, Client::MAX_CONTACTS + 1, ['name' => 'Amine']);

        $response = self::post('/api/clients', ['name' => 'Acme', 'contacts' => $contacts]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('contacts', $response['body']);
    }

    /**
     * **THE TWO ROUTES AND THEIR METHODS, pinned because each one is a breaking change to move.**
     *
     * `PUT`, `PATCH` and `DELETE` must NOT exist, and their absence is a decision recorded on
     * {@see \Twes\UI\Http\ApiResource\ClientResource}: an edit endpoint has to answer the contact-id question
     * first, and a delete has to answer what happens to an issued invoice that names the client. A verb that
     * appears here without that argument being made is the contract acquiring a shape nobody chose.
     *
     * There is also no collection `GET`, because a list needs pagination decided once — `CLAUDE.md` § "The API
     * contract is ours to design" rejects upstream's `per_page=999999` outright.
     */
    public function testTheClientRoutesExistAndTheForbiddenVerbsDoNot(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $methodsByPath = [];

        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();

            if (str_starts_with($path, '/api/clients')) {
                $methodsByPath[$path] = array_merge($methodsByPath[$path] ?? [], $route->getMethods());
            }
        }

        self::assertSame(['POST'], $methodsByPath['/api/clients'] ?? [], 'create');
        self::assertSame(['GET'], $methodsByPath['/api/clients/{id}'] ?? [], 'read only — no PUT, PATCH or DELETE');

        // SORTED, because the ORDER the router happens to register routes in is the framework's business and not
        // our contract's. Asserting on the unsorted list pins something we never decided, and it goes red on a
        // framework upgrade that changes nothing a client can see.
        $paths = array_keys($methodsByPath);
        sort($paths);

        self::assertSame(
            ['/api/clients', '/api/clients/{id}'],
            $paths,
            'and no other client route exists — a collection GET in particular is deliberately absent',
        );
    }

    /**
     * A DOMAIN BOUND REACHES THE PUBLISHED CONTRACT, so a client learns it from the schema rather than from a 422.
     *
     * `maxLength` survives onto a scalar property's schema, which is what makes this checkable at all — the invoice
     * suite records that `Assert\Count`'s `maxItems` does NOT survive onto a typed collection, so the contact cap
     * is enforced twice and documented nowhere. That gap is named there rather than papered over with a
     * hand-written `openapiContext`, which would be two copies of one bound.
     */
    public function testALengthBoundReachesThePublishedContract(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        self::assertSame(
            Contact::MAX_NAME_LENGTH,
            self::schemaNamed($schemas, 'NewContactInput')['properties']['name']['maxLength'] ?? null,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private static function schemaNamed(array $schemas, string $name): array
    {
        // MATCHED BY SUFFIX, not by an exact key. API Platform namespaces an operation-scoped input schema as
        // `Client.NewClientInput` while the nested ones keep their bare class names, and which form appears is the
        // framework's business rather than our contract's. Anchored at the end so a bare name cannot match a
        // namespaced key for a different class.
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
