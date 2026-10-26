<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Purchases\Application;

use App\Module\Purchases\Application\Receivers\ChooseReceiptReceiver;
use App\Module\Purchases\Application\Receivers\ReceivingMember;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryReceivingMembers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ChooseReceiptReceiverTest extends TestCase
{
    private InMemoryReceivingMembers $members;
    private ChooseReceiptReceiver $choose;
    private Company $acme;
    private Company $globex;

    protected function setUp(): void
    {
        $this->members = new InMemoryReceivingMembers();
        $this->choose = new ChooseReceiptReceiver($this->members);
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testAMemberIsCopiedByTheNameTheyBearNowWithTheirAccountBesideIt(): void
    {
        $sami = $this->members->join($this->acme, 'Sami Ben Ali');

        $receiver = $this->choose->receiver($this->acme, new ReceiverChoice($sami->toRfc4122()), null);

        self::assertInstanceOf(Receiver::class, $receiver);
        self::assertTrue(Receiver::named('Sami Ben Ali', $sami)->equals($receiver));
    }

    public function testANameTypedIsKeptAsTypedAndLinksNobody(): void
    {
        $receiver = $this->choose->receiver($this->acme, new ReceiverChoice(null, '  Le chauffeur de Sotumag '), null);

        self::assertInstanceOf(Receiver::class, $receiver);
        self::assertSame(['Le chauffeur de Sotumag', null], [$receiver->name, $receiver->contactId]);
    }

    public function testNobodyNamedIsNobody(): void
    {
        self::assertNull($this->choose->receiver($this->acme, null, null));
        self::assertNull($this->choose->receiver($this->acme, new ReceiverChoice(null, "  \u{00A0}"), null));
    }

    public function testAMemberOfAnotherCompanyOrNoMemberAtAllIsRefused(): void
    {
        $theirs = $this->members->join($this->globex, 'Ines Trabelsi');

        foreach ([$theirs->toRfc4122(), Uuid::v7()->toRfc4122(), 'not-an-id'] as $memberId) {
            $refused = $this->choose->receiver($this->acme, new ReceiverChoice($memberId), null);
            self::assertInstanceOf(Refusal::class, $refused, $memberId);
            self::assertSame('unknown_choice', $refused->reason);
        }
    }

    public function testANameTooLongIsRefusedByTheReceiversOwnRule(): void
    {
        $refused = $this->choose->receiver($this->acme, new ReceiverChoice(null, str_repeat('a', Receiver::NAME_MAX + 1)), null);

        self::assertInstanceOf(Refusal::class, $refused);
        self::assertSame('invalid_text', $refused->reason);
    }

    /** A form sends back the receiver it read: kept as copied, even when the member has since been renamed. */
    public function testTheReceiverAlreadyNamedIsKeptAsCopiedWhenTheMemberWasRenamedSince(): void
    {
        $sami = $this->members->join($this->acme, 'Sami');
        $current = Receiver::named('Sami', $sami);
        $this->members->rename($this->acme, $sami, 'Sami Ben Ali');

        $kept = $this->choose->receiver($this->acme, new ReceiverChoice($sami->toRfc4122()), $current);
        $typedAgain = $this->choose->receiver($this->acme, new ReceiverChoice(null, 'Sami'), Receiver::named('Sami'));

        self::assertSame($current, $kept);
        self::assertInstanceOf(Receiver::class, $typedAgain);
        self::assertSame('Sami', $typedAgain->name);
        $other = $this->choose->receiver($this->acme, new ReceiverChoice($sami->toRfc4122()), Receiver::named('Sami'));
        self::assertInstanceOf(Receiver::class, $other);
        self::assertSame('Sami Ben Ali', $other->name, 'a name typed, then the member picked, copies the member as named now');
    }

    public function testTheMembersAreOfferedWithTheOneRecordingFirst(): void
    {
        $this->members->join($this->acme, 'Amel');
        $me = $this->members->join($this->acme, 'Sami');
        $this->members->join($this->acme, 'Karim');

        self::assertSame(['Sami', 'Amel', 'Karim'], array_map(static fn (ReceivingMember $member): string => $member->name, $this->choose->offered($this->acme, $me)));
    }
}
