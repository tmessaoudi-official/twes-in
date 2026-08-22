<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;
use Twes\Domain\Client\PostalAddress;

/**
 * The party an invoice is addressed to, and the four things this aggregate refuses.
 *
 * **IMMUTABLE LIKE EVERY OTHER DOMAIN TYPE HERE**, so every `with…()` returns a new instance and the original
 * is untouched. That is asserted directly rather than assumed: `CLAUDE.md` § Architecture makes immutability
 * the load-bearing reason the persistence model is a separate mutable row class, so an accidental in-place
 * mutator here would quietly invalidate that whole argument.
 */
#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    private const ID = '0199a5b2-0000-7000-8000-0000000000c1';
    private const CONTACT_A = '0199a5b2-0000-7000-8000-00000000c001';
    private const CONTACT_B = '0199a5b2-0000-7000-8000-00000000c002';

    private static function client(string $name = 'Société Générale de Test'): Client
    {
        return Client::create(self::ID, $name);
    }

    private static function contact(string $id, string $name = 'Amine'): Contact
    {
        return new Contact($id, $name, null, null);
    }

    private static function address(): PostalAddress
    {
        return new PostalAddress('12 Rue de la Paix', null, '75002', 'Paris', 'FR');
    }

    public function testANewClientIsJustAnIdentityAndAName(): void
    {
        $client = self::client();

        self::assertSame(self::ID, $client->id());
        self::assertSame('Société Générale de Test', $client->name());
        self::assertNull($client->taxIdentifier());
        self::assertNull($client->address());
        self::assertSame([], $client->contacts());
    }

    /**
     * **A CLIENT IS CREATABLE FROM A NAME ALONE, and that is a decision rather than laziness.**
     *
     * Everything else — the address, the tax identifier, the people — is added when somebody knows it. Making
     * any of them mandatory here would make a client unrepresentable until its whole file was assembled, which
     * is not how anybody enters one; the stricter question is what an ISSUED document requires, and that
     * belongs to the e-invoicing wave.
     */
    public function testTheNameIsTrimmedOnStore(): void
    {
        self::assertSame('Acme', Client::create(self::ID, '  Acme  ')->name());
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(["\t\n"])]
    public function testABlankNameIsRefused(string $blank): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        Client::create(self::ID, $blank);
    }

    /** One shape per rule; `Unit\Shared\IdentifierTest` owns the whole refused set. */
    #[TestWith(['0199A5B2-0000-7000-8000-0000000000C1'])]
    #[TestWith(["0199a5b2-0000-7000-8000-0000000000c1\n"])]
    #[TestWith(['client-1'])]
    public function testAnIllFormedIdIsRefused(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase-hyphenated UUID');

        Client::create($id, 'Acme');
    }

    public function testAnOverlongNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('characters');

        Client::create(self::ID, str_repeat('a', Client::MAX_NAME_LENGTH + 1));
    }

    // ---------------------------------------------------------------------------------------------------
    // IMMUTABILITY
    // ---------------------------------------------------------------------------------------------------

    public function testEveryMutatorReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $original = self::client();
        $address = self::address();

        $renamed = $original->withName('Renamed');
        $taxed = $original->withTaxIdentifier('TN1234567X');
        $addressed = $original->withAddress($address);
        $peopled = $original->withContact(self::contact(self::CONTACT_A));

        self::assertNotSame($original, $renamed);
        self::assertSame('Société Générale de Test', $original->name(), 'withName mutated the original');
        self::assertNull($original->taxIdentifier(), 'withTaxIdentifier mutated the original');
        self::assertNull($original->address(), 'withAddress mutated the original');
        self::assertSame([], $original->contacts(), 'withContact mutated the original');

        self::assertSame('Renamed', $renamed->name());
        self::assertSame('TN1234567X', $taxed->taxIdentifier());
        self::assertSame($address, $addressed->address(), 'the address object itself must be carried through');
        self::assertCount(1, $peopled->contacts());
    }

    /** Each mutator changes ONE thing — a `withName` that also dropped the address would pass a weaker test. */
    public function testAMutatorCarriesEveryOtherFieldThrough(): void
    {
        $client = self::client()
            ->withTaxIdentifier('TN1234567X')
            ->withAddress(self::address())
            ->withContact(self::contact(self::CONTACT_A))
            ->withName('Renamed');

        self::assertSame('Renamed', $client->name());
        self::assertSame('TN1234567X', $client->taxIdentifier());
        self::assertNotNull($client->address());
        self::assertCount(1, $client->contacts());
        self::assertSame(self::ID, $client->id());
    }

    /** An address and a tax identifier can be CLEARED, which is a different act from never having had one. */
    public function testTheOptionalPartsCanBeCleared(): void
    {
        $client = self::client()->withTaxIdentifier('TN1234567X')->withAddress(self::address());

        self::assertNull($client->withTaxIdentifier(null)->taxIdentifier());
        self::assertNull($client->withAddress(null)->address());
    }

    /** Blank normalises to absent, so '' and null are not two spellings of "no tax identifier". */
    #[TestWith([''])]
    #[TestWith(['  '])]
    public function testABlankTaxIdentifierBecomesNull(string $blank): void
    {
        self::assertNull(self::client()->withTaxIdentifier($blank)->taxIdentifier());
    }

    public function testTheTaxIdentifierIsTrimmedAndBounded(): void
    {
        self::assertSame('TN1234567X', self::client()->withTaxIdentifier(' TN1234567X ')->taxIdentifier());

        $this->expectException(\InvalidArgumentException::class);
        self::client()->withTaxIdentifier(str_repeat('X', Client::MAX_TAX_IDENTIFIER_LENGTH + 1));
    }

    // ---------------------------------------------------------------------------------------------------
    // CONTACTS
    // ---------------------------------------------------------------------------------------------------

    public function testContactsAreKeptInTheOrderTheyWereAdded(): void
    {
        $client = self::client()
            ->withContact(self::contact(self::CONTACT_A, 'First'))
            ->withContact(self::contact(self::CONTACT_B, 'Second'));

        self::assertSame(['First', 'Second'], array_map(static fn(Contact $c): string => $c->name, $client->contacts()));
    }

    /**
     * **CONTACT IDS ARE UNIQUE WITHIN A CLIENT, and this is the invariant a positional model could not state.**
     *
     * Two contacts sharing an id makes `withoutContact()` ambiguous and makes any stored reference to a contact
     * — the person an invoice is e-mailed to — resolve to whichever the persistence layer returned first.
     */
    public function testTwoContactsWithTheSameIdAreRefused(): void
    {
        $client = self::client()->withContact(self::contact(self::CONTACT_A, 'First'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already');

        $client->withContact(self::contact(self::CONTACT_A, 'Second'));
    }

    public function testAContactIsRemovedByItsIdAndTheOthersKeepTheirs(): void
    {
        $client = self::client()
            ->withContact(self::contact(self::CONTACT_A, 'First'))
            ->withContact(self::contact(self::CONTACT_B, 'Second'));

        $remaining = $client->withoutContact(self::CONTACT_A);

        self::assertCount(1, $remaining->contacts());
        self::assertSame('Second', $remaining->contacts()[0]->name);
        self::assertSame(self::CONTACT_B, $remaining->contacts()[0]->id);
        self::assertCount(2, $client->contacts(), 'withoutContact mutated the original');
    }

    /**
     * **REMOVING A CONTACT THAT IS NOT THERE IS REFUSED RATHER THAN IGNORED.**
     *
     * A silent no-op would let a client believe it deleted somebody who is still on the record and still
     * receiving invoices. The same reasoning as `document.line_not_found`: acting on stale state is a 409 the
     * UI must explain, not something to swallow.
     */
    public function testRemovingAnUnknownContactIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not');

        self::client()->withContact(self::contact(self::CONTACT_A))->withoutContact(self::CONTACT_B);
    }

    /** The removal argument obeys the identifier rule too — an ill-formed id must not reach a lookup. */
    public function testRemovingByAnIllFormedIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::client()->withoutContact('not-a-uuid');
    }

    /**
     * **THE CONTACT LIST IS BOUNDED**, for the reason `Invoice::MAX_LINES` is: a write endpoint is where an
     * unbounded client-supplied collection becomes a real one, and an unbounded one is a memory and a
     * render-time problem rather than a business rule.
     */
    public function testTooManyContactsAreRefused(): void
    {
        $client = self::client();

        for ($i = 0; $i < Client::MAX_CONTACTS; ++$i) {
            $client = $client->withContact(self::contact(\sprintf('0199a5b2-0000-7000-8000-%012x', $i)));
        }

        self::assertCount(Client::MAX_CONTACTS, $client->contacts());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contact');

        $client->withContact(self::contact('0199a5b2-0000-7000-8000-ffffffffffff'));
    }

    /** @return iterable<string, array{string}> */
    public static function illFormedContactIds(): iterable
    {
        yield 'uppercase' => ['0199A5B2-0000-7000-8000-00000000C001'];
        yield 'not a uuid' => ['contact-1'];
    }

    #[DataProvider('illFormedContactIds')]
    public function testRebuildingFromPersistedStateStillRefusesAnIllFormedContactId(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Client::fromPersistedState(self::ID, 'Acme', null, null, [self::contact(self::CONTACT_A), self::contact($id)]);
    }

    /**
     * **REHYDRATION GOES THROUGH THE SAME GUARDS**, which is what stops a corrupt row becoming a domain object
     * no code path could otherwise have built. `Invoice::fromPersistedState()` sets the precedent.
     */
    public function testRebuildingFromPersistedStateCarriesEverything(): void
    {
        $client = Client::fromPersistedState(
            self::ID,
            'Acme',
            'TN1234567X',
            self::address(),
            [self::contact(self::CONTACT_A), self::contact(self::CONTACT_B)],
        );

        self::assertSame(self::ID, $client->id());
        self::assertSame('Acme', $client->name());
        self::assertSame('TN1234567X', $client->taxIdentifier());
        self::assertNotNull($client->address());
        self::assertCount(2, $client->contacts());
    }

    /** A duplicate id must be refused on rehydration too — a corrupt row is exactly where one would come from. */
    public function testRebuildingFromPersistedStateRefusesDuplicateContactIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already');

        Client::fromPersistedState(
            self::ID,
            'Acme',
            null,
            null,
            [self::contact(self::CONTACT_A), self::contact(self::CONTACT_A)],
        );
    }
}
