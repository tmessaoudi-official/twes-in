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

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twes\Application\Client\CreateClientHandler;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;
use Twes\Domain\Client\PostalAddress;
use Twes\Tests\Support\FixedIdGenerator;
use Twes\Tests\Support\InMemoryClientRepository;
use Twes\Tests\Support\RecordingTransactionalScope;
use Twes\UI\Http\ApiResource\NewClientInput;
use Twes\UI\Http\ApiResource\NewContactInput;
use Twes\UI\Http\ApiResource\PostalAddressInput;
use Twes\UI\Http\State\ClientProvider;
use Twes\UI\Http\State\CreateClientProcessor;

/**
 * THE PROCESSOR AND THE PROVIDER DRIVEN DIRECTLY — the status-code mapping and the response shape.
 *
 * {@see ClientWriteSurfaceTest} goes through the real kernel, which is right for validation cascading and the
 * published contract and wrong for everything here: reaching the processor over HTTP with a VALID payload needs a
 * database and a bound tenant, so a kernel test can only ever assert refusals. Driving the two classes directly is
 * what makes the successful shape — which id, which contacts, in which order, wrapped in which DTO — assertable
 * without one.
 *
 * **NO KERNEL AND NO DATABASE.** The handler runs against {@see InMemoryClientRepository}, so what is proven here
 * is the translation between the wire's DTOs and the domain, not persistence. The round trip through real columns
 * is `DoctrineClientRepositoryTest`'s subject and is deliberately not re-proven with a fake.
 */
#[CoversClass(CreateClientProcessor::class)]
#[CoversClass(ClientProvider::class)]
final class ClientProcessorTest extends TestCase
{
    private const STORED = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private InMemoryClientRepository $clients;
    private FixedIdGenerator $identifiers;
    private RecordingTransactionalScope $transaction;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientRepository();
        $this->identifiers = new FixedIdGenerator();
        $this->transaction = new RecordingTransactionalScope();
    }

    /**
     * THE WHOLE SHAPE OF A CREATE RESPONSE, which is the case a kernel test cannot reach without a database.
     *
     * Everything a client receives is asserted here: the server-minted id, the scalars, the address as a nested
     * object rather than five flat fields, and the contacts in the order they were sent with ids of their own.
     */
    public function testACreatedClientIsReturnedInFull(): void
    {
        $resource = $this->processor()->process(
            new NewClientInput(
                'Société Générale de Test',
                'TN1234567X',
                new PostalAddressInput('12 Rue de la Paix', 'Paris', 'FR', 'Bâtiment C', '75002'),
                [
                    // NOT IN ALPHABETICAL ORDER, deliberately. With `Amine` first, a sort-by-name anywhere in
                    // `ClientRepresentation` would produce the same list and this case would not notice — the
                    // same *a probe's reach is bounded by its fixture's value space* rule that made the missing
                    // `ORDER BY position` invisible until the fixture stopped supplying the ordering by accident.
                    new NewContactInput('Yasmine', 'yasmine@example.test', '+216 71 000 000'),
                    new NewContactInput('Amine'),
                ],
            ),
            new Post(),
        );

        self::assertSame($this->identifiers->handedOut[0], $resource->id);
        self::assertSame('Société Générale de Test', $resource->name);
        self::assertSame('TN1234567X', $resource->taxIdentifier);

        self::assertNotNull($resource->address);
        self::assertSame('12 Rue de la Paix', $resource->address->line1);
        self::assertSame('Bâtiment C', $resource->address->line2);
        self::assertSame('75002', $resource->address->postcode);
        self::assertSame('Paris', $resource->address->city);
        self::assertSame('FR', $resource->address->countryCode);

        self::assertCount(2, $resource->contacts);
        self::assertSame(
            ['Yasmine', 'Amine'],
            array_map(static fn($contact): string => $contact->name, $resource->contacts),
            'the order sent is the order returned — nothing sorts this list',
        );
        self::assertSame('yasmine@example.test', $resource->contacts[0]->email);
        self::assertSame('+216 71 000 000', $resource->contacts[0]->phone);
        self::assertNull($resource->contacts[1]->email, 'an absent e-mail comes back absent, not empty');
    }

    /** No address means `null`, never an object of empty strings — a client would print an empty address block. */
    public function testAClientWithNoAddressHasNoAddressObject(): void
    {
        $resource = $this->processor()->process(new NewClientInput('Acme'), new Post());

        self::assertNull($resource->address);
        self::assertSame([], $resource->contacts);
        self::assertNull($resource->taxIdentifier);
    }

    /**
     * **A DOMAIN REFUSAL DURING CONVERSION IS A 422, and this case can only exist because the processor is driven
     * directly.** Over HTTP the validator would have refused this payload first, which is the whole design — see
     * {@see CreateClientProcessor}'s docblock on why the handler is deliberately OUTSIDE the `try`.
     *
     * What it pins is the arm that catches it. Without the `catch`, an `\InvalidArgumentException` reaching
     * Symfony's exception listener untranslated is a 500: our fault reported for the caller's error.
     */
    public function testADomainRefusalWhileParsingTheAddressIsAnUnprocessableEntity(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor()->process(
            // `fr` LOWERCASE. `PostalAddress` refuses it rather than upcasing, and no validator runs here.
            new NewClientInput('Acme', null, new PostalAddressInput('12 Rue de la Paix', 'Paris', 'fr')),
            new Post(),
        );
    }

    /**
     * **A REPOSITORY FAILURE IS NOT REPORTED AS THE CALLER'S ERROR — the property the `try` boundary exists for,
     * and until 2026-08-22 nothing pinned it.**
     *
     * {@see CreateClientProcessor} keeps `handle()` outside its `catch (\InvalidArgumentException|\DomainException)`
     * because the invoice path learned over three rounds that a whole-call catch swallows HYDRATION failures:
     * corrupt column data raises `\InvalidArgumentException` from deep inside a mapper, and a wide catch reports
     * OUR broken row to the client as a 422 naming an internal column. The only handler-failure case in this class
     * threw a `\LogicException`, which that arm never catches anyway — so the mutant *"widen the `try` around
     * `handle()`"* left every test green and the guarantee was enforced by nothing.
     *
     * This is that mutant's killer: a repository whose read-back raises the exact exception type the conversion
     * arm catches. The processor must let it through as itself, not translate it.
     */
    public function testARepositoryFailureIsNotReportedAsTheCallersError(): void
    {
        $processor = new CreateClientProcessor(
            new CreateClientHandler(new HydrationFailingClientRepository(), $this->identifiers, $this->transaction),
        );

        try {
            $processor->process(new NewClientInput('Acme'), new Post());
            self::fail('the repository failure must not be swallowed');
        } catch (UnprocessableEntityHttpException) {
            self::fail(
                'a repository failure was reported as a 422 — the caller is told to fix a payload that was never '
                . 'the problem, and a corrupt stored row is hidden behind a message naming an internal column',
            );
        } catch (\InvalidArgumentException $ourFault) {
            // A 500 is what `error.internal` means, and Symfony's listener produces one for an untranslated
            // exception. What matters here is that it left this class UNWRAPPED.
            self::assertStringContainsString('stored row', $ourFault->getMessage());
        }
    }

    /**
     * The wrong `input:` on the operation is a `\LogicException` and never a 4xx.
     *
     * Only a misconfigured operation can reach it, so it is our fault — `error.internal` per `CLAUDE.md`
     * § "Translation keys". A 400 here would tell a client to fix a payload that was never the problem.
     */
    public function testAPayloadOfTheWrongTypeIsOurFault(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/wired to an operation/');

        $this->processor()->process(['name' => 'Acme'], new Post());
    }

    public function testAStoredClientIsProvidedInFull(): void
    {
        $this->clients->save(
            Client::create(self::STORED, 'Acme')
                ->withAddress(new PostalAddress('12 Rue de la Paix', null, null, 'Paris', 'FR'))
                ->withContact(new Contact('0199a5b2-0000-7000-8000-00000000c001', 'Amine', null, null)),
        );

        $resource = $this->provider()->provide(new Get(), ['id' => self::STORED]);

        self::assertSame(self::STORED, $resource->id);
        self::assertSame('Acme', $resource->name);
        self::assertNotNull($resource->address);
        self::assertSame('Paris', $resource->address->city);
        self::assertCount(1, $resource->contacts);
    }

    /** The read runs in a transaction, without which the tenant is unbound and the row invisible. */
    public function testTheReadRunsInsideATransaction(): void
    {
        $this->clients->save(Client::create(self::STORED, 'Acme'));

        $this->provider()->provide(new Get(), ['id' => self::STORED]);

        self::assertSame(1, $this->transaction->entered);
    }

    /**
     * **EVERY SHAPE OF MISS THIS FIXTURE CAN EXPRESS GIVES THE SAME ANSWER**, and that sameness is the security
     * property. Distinguishing "malformed" from "absent" tells a prober its guess had the right SHAPE.
     *
     * The three exercised here are a well-formed id nobody owns, an id of the wrong shape, and an UPPERCASE
     * spelling of a real id — the last mattering because `TenantId::fromString()` normalises a non-canonical id
     * while `DocumentIdentity` refuses one, so a client id must not quietly match in a spelling the domain does
     * not accept.
     *
     * **WHAT THIS CASE DOES NOT PROVE, corrected at round 5 (R5T-2): "another tenant's".** This docblock claimed
     * it, and claimed the third shape was "an id that is not a string at all" — neither describes the loop below.
     * The class runs on an IN-MEMORY repository, where a second tenant cannot exist at all, so no assertion here
     * could ever have covered the cross-tenant direction. It is proven against a real policed schema instead, in
     * `DoctrineClientRepositoryTest::testAnotherTenantsClientIsNotFound`, where two bound connections make the
     * shape expressible. A fixture that cannot express a dangerous shape cannot detect it — and a docblock that
     * says otherwise is worse than silence, because it retires the question.
     */
    public function testEveryWayOfNotFindingAClientIsTheSame404(): void
    {
        foreach ([
            'a well-formed id nobody owns' => '11111111-1111-4111-8111-111111111111',
            'an id of the wrong shape' => 'not-a-uuid',
            'an uppercase spelling of a real id' => strtoupper(self::STORED),
        ] as $why => $id) {
            try {
                $this->provider()->provide(new Get(), ['id' => $id]);
                self::fail(\sprintf('expected a 404 for %s', $why));
            } catch (NotFoundHttpException $refused) {
                self::assertStringContainsString('No such client.', $refused->getMessage(), $why);
            }
        }
    }

    /** API Platform hands over whatever matched the route, so a non-string is a caller error and not a crash. */
    public function testANonStringIdIsA404RatherThanATypeError(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider()->provide(new Get(), ['id' => 42]);
    }

    private function processor(): CreateClientProcessor
    {
        return new CreateClientProcessor(
            new CreateClientHandler($this->clients, $this->identifiers, $this->transaction),
        );
    }

    private function provider(): ClientProvider
    {
        return new ClientProvider($this->clients, $this->transaction);
    }
}

/**
 * A repository whose read-back raises the exception type the processor's CONVERSION arm catches.
 *
 * It is the shape a real hydration failure has: `InvalidMoneyAmount`, `UnknownCurrency` and `InvalidRate` all
 * extend `\InvalidArgumentException` and are raised from deep inside a mapper when stored column data no longer
 * parses. `CLAUDE.md` § Gotchas records that being reported to clients as a 422 naming an internal column on the
 * invoice path, three separate times.
 */
final class HydrationFailingClientRepository implements \Twes\Domain\Client\ClientRepository
{
    public function save(Client $client): void {}

    public function find(string $id): ?Client
    {
        throw new \InvalidArgumentException('A stored row for this client can no longer be parsed.');
    }
}
