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
use Twes\Domain\Client\Contact;

/**
 * A person at a client — EN 16931's BG-9 BUYER CONTACT, and the reason it carries an ID of its own.
 *
 * **A CONTACT IS AN ENTITY, NOT A POSITION IN A LIST**, which is the one place this type deliberately differs
 * from `DocumentLine`. A line is identified by where it sits on its document — `withoutLine(int $position)` —
 * because a line has no life outside the document and nothing ever refers to one. A contact is referred to:
 * Wave 4 sends a PDF to one, Wave 10 invites one to the client portal. Under positional identity, deleting the
 * first of three contacts silently re-points every reference to the remaining two, which for "who do we e-mail
 * this invoice to" is a defect nobody would see until the wrong person received an invoice.
 */
#[CoversClass(Contact::class)]
final class ContactTest extends TestCase
{
    private const ID = '0199a5b2-0000-7000-8000-00000000c001';

    private static function contact(
        string $id = self::ID,
        string $name = 'Amine Ben Salah',
        ?string $email = 'amine@example.test',
        ?string $phone = '+216 71 000 000',
    ): Contact {
        return new Contact($id, $name, $email, $phone);
    }

    public function testAFullContactIsCarriedThrough(): void
    {
        $contact = self::contact();

        self::assertSame(self::ID, $contact->id);
        self::assertSame('Amine Ben Salah', $contact->name);
        self::assertSame('amine@example.test', $contact->email);
        self::assertSame('+216 71 000 000', $contact->phone);
    }

    /** A name is all a contact needs — an e-mail and a phone are how you reach them, not who they are. */
    public function testEmailAndPhoneAreOptional(): void
    {
        $contact = self::contact(email: null, phone: null);

        self::assertNull($contact->email);
        self::assertNull($contact->phone);
    }

    /** Blank normalises to absent, for the reason {@see PostalAddressTest} gives: one fact, one spelling. */
    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testABlankOptionalPartBecomesNull(string $blank): void
    {
        $contact = self::contact(email: $blank, phone: $blank);

        self::assertNull($contact->email);
        self::assertNull($contact->phone);
    }

    public function testTheNameIsTrimmedOnStore(): void
    {
        self::assertSame('Amine Ben Salah', self::contact(name: '  Amine Ben Salah  ')->name);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testABlankNameIsRefused(string $blank): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        self::contact(name: $blank);
    }

    /**
     * **THE ID OBEYS THE ONE IDENTIFIER RULE**, delegated rather than re-checked here.
     *
     * One shape per rule the predicate enforces rather than the whole refused set — this asserts the
     * DELEGATION, and `Unit\Shared\IdentifierTest` owns the shapes. That split is exactly what the extraction
     * in `Domain/Shared/Identifier` was for.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'uppercase' => ['0199A5B2-0000-7000-8000-00000000C001'];
        yield 'a trailing newline' => [self::ID . "\n"];
        yield 'a prepended payload' => ['junk' . self::ID];
        yield 'not a uuid at all' => ['contact-1'];
        yield 'empty' => [''];
    }

    #[DataProvider('refusedIds')]
    public function testAnIllFormedIdIsRefused(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase-hyphenated UUID');

        self::contact(id: $id);
    }

    /**
     * **THE E-MAIL IS VALIDATED, because an invoice sent to a malformed address is silently not delivered.**
     *
     * That is the failure mode worth refusing at the boundary rather than discovering from a bounce: nothing in
     * this system watches for one, so an unvalidated address means an invoice nobody receives and nobody knows
     * was not received.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedEmails(): iterable
    {
        yield 'no at sign' => ['amine.example.test'];
        yield 'no domain' => ['amine@'];
        yield 'no local part' => ['@example.test'];
        yield 'two at signs' => ['amine@@example.test'];
        yield 'a space inside' => ['amine ben@example.test'];
        yield 'a newline INSIDE, which trimming cannot rescue' => ["amine@exa\nmple.test"];
        yield 'just an at sign' => ['@'];
    }

    #[DataProvider('refusedEmails')]
    public function testAMalformedEmailIsRefused(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('e-mail');

        self::contact(email: $email);
    }

    /** And ordinary addresses are accepted, so the cases above are not passing because everything is refused. */
    #[TestWith(['amine@example.test'])]
    #[TestWith(['first.last+tag@sub.example.test'])]
    public function testAWellFormedEmailIsAccepted(string $email): void
    {
        self::assertSame($email, self::contact(email: $email)->email);
    }

    /**
     * **SURROUNDING WHITESPACE IS TRIMMED AND THEN VALIDATED, IN THAT ORDER — a decision, not an accident.**
     *
     * An address pasted out of a mail client or a spreadsheet arrives with a trailing newline or space
     * routinely, and refusing it would be refusing a perfectly good address for the way it was copied. The
     * identifier rule's *"refuse, never normalise"* argument does NOT transfer here: it applies to values used
     * as KEYS, where two spellings compare unequal and silently split one thing into two. An e-mail is a
     * destination, and every other human-entered string on this aggregate is trimmed the same way.
     *
     * The case above pins the limit of that leniency — a newline INSIDE the address is still refused, because
     * trimming cannot rescue it and it is not a copy-paste artefact.
     */
    #[TestWith(["amine@example.test\n"])]
    #[TestWith(['  amine@example.test  '])]
    #[TestWith(["\tamine@example.test\r\n"])]
    public function testSurroundingWhitespaceIsTrimmedRatherThanRefused(string $email): void
    {
        self::assertSame('amine@example.test', self::contact(email: $email)->email);
    }

    public function testAnOverlongNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('characters');

        self::contact(name: str_repeat('a', Contact::MAX_NAME_LENGTH + 1));
    }

    /** The bound counts characters rather than bytes — a name is refused for length, never for its script. */
    public function testTheNameBoundCountsCharactersAndNotBytes(): void
    {
        $arabic = str_repeat('ش', Contact::MAX_NAME_LENGTH);

        self::assertGreaterThan(Contact::MAX_NAME_LENGTH, \strlen($arabic));
        self::assertSame($arabic, self::contact(name: $arabic)->name);
    }

    public function testAnOverlongPhoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::contact(phone: str_repeat('1', Contact::MAX_PHONE_LENGTH + 1));
    }
}
