<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Application\Receivers\ReceiverContactRefused;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryReceiverContacts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** A person's choice of who received: one of the customer's contacts, a name typed, or a name typed and added. */
final class ChooseReceiverTest extends TestCase
{
    private InMemoryReceiverContacts $contacts;
    private ChooseReceiver $choose;
    private Company $company;
    private Uuid $customer;

    protected function setUp(): void
    {
        $this->contacts = new InMemoryReceiverContacts();
        $this->choose = new ChooseReceiver($this->contacts);
        $this->company = new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->customer = Uuid::v7();
    }

    public function testNobodyNamedIsNoReceiver(): void
    {
        self::assertNull($this->choose->receiver($this->company, $this->customer, null, null));
        self::assertNull($this->choose->receiver($this->company, $this->customer, new ReceiverChoice(name: " \u{00A0} "), null));
        self::assertSame([], $this->contacts->added, 'an empty name with the tick adds nobody');
    }

    public function testAContactOfTheCustomerIsCopiedByNameAndAnyOtherIsRefused(): void
    {
        $ahmed = Uuid::v7();
        $this->contacts->contacts[$this->customer->toRfc4122()][$ahmed->toRfc4122()] = 'Ahmed Ben Salah';
        $elsewhere = Uuid::v7();
        $this->contacts->contacts[Uuid::v7()->toRfc4122()][$elsewhere->toRfc4122()] = 'Someone else';

        self::assertEquals(Receiver::named('Ahmed Ben Salah', $ahmed), $this->choose->receiver($this->company, $this->customer, new ReceiverChoice($ahmed->toRfc4122(), 'typed beside it'), null));
        foreach (['another customer\'s contact' => $elsewhere->toRfc4122(), 'no contact' => Uuid::v7()->toRfc4122(), 'not an id' => 'ahmed'] as $case => $id) {
            $refused = $this->choose->receiver($this->company, $this->customer, new ReceiverChoice($id), null);
            self::assertInstanceOf(Refusal::class, $refused, $case);
            self::assertSame('unknown_choice', $refused->reason, $case);
        }
    }

    public function testATypedNameIsKeptAndAddedToTheContactsOnlyWhenAsked(): void
    {
        self::assertEquals(Receiver::named('Sami'), $this->choose->receiver($this->company, $this->customer, new ReceiverChoice(name: ' Sami '), null));
        self::assertSame([], $this->contacts->added);

        $added = $this->choose->receiver($this->company, $this->customer, new ReceiverChoice(name: ' Sami Trabelsi ', addToContacts: true), null);

        self::assertInstanceOf(Receiver::class, $added);
        self::assertSame([[$this->customer->toRfc4122(), 'Sami Trabelsi']], $this->contacts->added);
        self::assertNotNull($added->contactId);
        self::assertSame('Sami Trabelsi', $this->contacts->nameOf($this->company, $this->customer, $added->contactId));
    }

    public function testAChoiceNamesTheReceiverAlreadyNamedWhenSentBackOrSentAgain(): void
    {
        $ahmed = Uuid::v7();
        $contact = Receiver::named('Ahmed Ben Salah', $ahmed);
        $typed = Receiver::named('Sami');

        self::assertTrue((new ReceiverChoice($ahmed->toRfc4122(), 'anything'))->names($contact), 'the same contact');
        self::assertFalse((new ReceiverChoice(Uuid::v7()->toRfc4122()))->names($contact), 'another contact');
        self::assertTrue((new ReceiverChoice(name: ' Sami '))->names($typed), 'the same name typed');
        self::assertFalse((new ReceiverChoice(name: 'Sami', addToContacts: true))->names($typed), 'the same name, now to be added');
        self::assertTrue((new ReceiverChoice(name: 'Ahmed Ben Salah', addToContacts: true))->names($contact), 'sent again once added');
        self::assertFalse((new ReceiverChoice(name: 'Samir'))->names($typed));
        self::assertFalse((new ReceiverChoice($ahmed->toRfc4122()))->names($typed), 'a contact where a name was typed');
        self::assertFalse((new ReceiverChoice(name: 'Sami'))->names(null));
    }

    public function testANameThatCannotBePrintedOrMadeAContactIsRefusedWithItsReason(): void
    {
        $long = $this->choose->receiver($this->company, $this->customer, new ReceiverChoice(name: str_repeat('a', Receiver::NAME_MAX + 1), addToContacts: true), null);
        self::assertInstanceOf(Refusal::class, $long);
        self::assertSame(['invalid_text', ['max' => Receiver::NAME_MAX]], [$long->reason, $long->params]);
        self::assertSame([], $this->contacts->added, 'nothing is added for a name refused');

        $this->contacts->refuseAdding = new ReceiverContactRefused('invalid_text', 'At most 100 characters.', ['max' => 100]);
        $refused = $this->choose->receiver($this->company, $this->customer, new ReceiverChoice(name: 'Sami', addToContacts: true), null);
        self::assertInstanceOf(Refusal::class, $refused);
        self::assertSame(['invalid_text', ['max' => 100]], [$refused->reason, $refused->params]);
    }
}
