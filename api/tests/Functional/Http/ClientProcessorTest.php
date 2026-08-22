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
                    new NewContactInput('Amine', 'amine@example.test', '+216 71 000 000'),
                    new NewContactInput('Yasmine'),
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
        self::assertSame('Amine', $resource->contacts[0]->name);
        self::assertSame('amine@example.test', $resource->contacts[0]->email);
        self::assertSame('+216 71 000 000', $resource->contacts[0]->phone);
        self::assertSame('Yasmine', $resource->contacts[1]->name);
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
     * **ALL THREE WAYS A LOOKUP CAN FAIL GIVE THE SAME ANSWER**, and that sameness is the security property.
     *
     * A well-formed id nobody owns, an id of the wrong shape, and an id that is not a string at all: 404 in every
     * case. Distinguishing "malformed" from "absent" would tell a prober its guess had the right SHAPE, and
     * distinguishing "absent" from "another tenant's" would confirm a client's existence to somebody not entitled
     * to know it — which is what row-level security exists to prevent, not merely tolerate.
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
