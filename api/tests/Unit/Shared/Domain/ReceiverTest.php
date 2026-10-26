<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\ReceiverRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Who received a document: a name as typed or copied from a contact, and the contact it came from, if any. */
final class ReceiverTest extends TestCase
{
    public function testANameIsKeptAsTypedWithoutTheInvisibleCharactersAtItsEnds(): void
    {
        $contact = Uuid::v7();
        $receiver = Receiver::named("\u{00A0} Ahmed Ben Salah\u{200B} ", $contact);

        self::assertSame(['Ahmed Ben Salah', $contact], [$receiver->name, $receiver->contactId]);
        self::assertNull(Receiver::named('Sami')->contactId, 'a typed name names no contact');
    }

    /** @return iterable<string, array{string, string, array<string, int>}> */
    public static function refusedNames(): iterable
    {
        yield 'empty' => ['', 'value_required', []];
        yield 'spaces only' => ["  \u{00A0}", 'value_required', []];
        yield 'longer than a contact\'s two names' => [str_repeat('a', Receiver::NAME_MAX + 1), 'invalid_text', ['max' => Receiver::NAME_MAX]];
    }

    /** @param array<string, int> $params */
    #[DataProvider('refusedNames')]
    public function testANameThatCannotBePrintedIsRefusedWithItsReason(string $name, string $reason, array $params): void
    {
        $refusal = Receiver::refusal($name);

        self::assertNotNull($refusal);
        self::assertSame([$reason, $params], [$refusal->reason, $refusal->params]);
        self::assertNull(Receiver::refusal(str_repeat('a', Receiver::NAME_MAX)), 'the longest name is taken');
    }

    public function testTheRuleRefusesAMissingReceiverWhenRequiredAndAnyReceiverWhenOff(): void
    {
        $sami = Receiver::named('Sami');

        self::assertSame('receiver_required', ReceiverRule::Required->refusal(null)?->reason);
        self::assertNull(ReceiverRule::Required->refusal($sami));
        self::assertNull(ReceiverRule::Optional->refusal(null));
        self::assertNull(ReceiverRule::Optional->refusal($sami));
        self::assertNull(ReceiverRule::Off->refusal(null));
        self::assertSame('receiver_off', ReceiverRule::Off->refusal($sami)?->reason);
        self::assertSame(['off', 'optional', 'required'], array_map(static fn (ReceiverRule $rule): string => $rule->value, ReceiverRule::cases()));
    }
}
