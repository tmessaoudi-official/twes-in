<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Inbox\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Inbox\Domain\InboxItem;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

/**
 * One notification as its recipient keeps it: the notification centre's row. The push over Centrifugo is best-effort,
 * this row is the truth (docs/SPEC.md § 7, 2026-09-13).
 */
final class InboxItemTest extends TestCase
{
    public function testANewItemIsUnreadAndKeepsWhatItWasGiven(): void
    {
        $recipient = new User(Email::fromString('amel@twes.local'), 'Amel');
        $company = new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $at = new \DateTimeImmutable('2026-09-13T10:00:00+00:00');

        $item = new InboxItem($recipient, $company, 'membership.added', ['company' => 'Demo', 'role' => 'member'], $at);

        self::assertFalse($item->isRead());
        self::assertNull($item->getReadAt());
        self::assertSame($recipient, $item->getRecipient());
        self::assertSame($company, $item->getCompany());
        self::assertSame('membership.added', $item->getType());
        self::assertSame(['company' => 'Demo', 'role' => 'member'], $item->getPayload());
        self::assertSame($at, $item->getCreatedAt());
    }

    public function testAPersonalItemBelongsToNoCompany(): void
    {
        $item = new InboxItem(new User(Email::fromString('amel@twes.local'), 'Amel'), null, 'account.notice', [], new \DateTimeImmutable());

        self::assertNull($item->getCompany());
    }

    public function testMarkingReadRecordsTheFirstTimeOnly(): void
    {
        $item = new InboxItem(new User(Email::fromString('amel@twes.local'), 'Amel'), null, 'account.notice', [], new \DateTimeImmutable('2026-09-13T10:00:00+00:00'));

        $item->markRead(new \DateTimeImmutable('2026-09-13T10:05:00+00:00'));
        $item->markRead(new \DateTimeImmutable('2026-09-13T11:00:00+00:00'));

        self::assertTrue($item->isRead());
        self::assertEquals(new \DateTimeImmutable('2026-09-13T10:05:00+00:00'), $item->getReadAt());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['added'];
        yield 'upper case' => ['Membership.Added'];
        yield 'spaces' => ['membership added'];
        yield 'trailing dot' => ['membership.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTypes')]
    public function testATypeIsADottedLowerCaseCode(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InboxItem(new User(Email::fromString('amel@twes.local'), 'Amel'), null, $type, [], new \DateTimeImmutable());
    }
}
