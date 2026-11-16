<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Inbox\Infrastructure;

use App\Inbox\Application\MailStop;
use App\Inbox\Infrastructure\Mail\HmacMailStopTokens;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HmacMailStopTokensTest extends TestCase
{
    private const string OLD = 'an-old-key-of-at-least-thirty-two-characters';
    private const string NEW = 'a-new-key-of-at-least-thirty-two-characters!';

    public function testATokenReadsBackWhatItStops(): void
    {
        $tokens = new HmacMailStopTokens([self::OLD]);
        $stop = new MailStop(Uuid::v7(), Uuid::v7(), 'invoice.paid');

        self::assertEquals($stop, $tokens->read($tokens->issue($stop)));
    }

    /** A stop link never expires (docs/SPEC.md § 7, 2026-10-09 05:29): a new key keeps every link already sent working. */
    public function testALinkSignedUnderAnEarlierKeyStillReadsAfterTheKeyChanged(): void
    {
        $stop = new MailStop(Uuid::v7(), null, 'invoice.paid');
        $sent = (new HmacMailStopTokens([self::OLD]))->issue($stop);

        $rotated = new HmacMailStopTokens([self::NEW, self::OLD]);

        self::assertEquals($stop, $rotated->read($sent));
        self::assertNotSame($sent, $rotated->issue($stop), 'a new link is signed under the first key');
        self::assertEquals($stop, (new HmacMailStopTokens([self::NEW]))->read($rotated->issue($stop)));
    }

    public function testALinkUnderAKeyNoLongerListedIsRefused(): void
    {
        $sent = (new HmacMailStopTokens([self::OLD]))->issue(new MailStop(Uuid::v7(), null, 'invoice.paid'));

        self::assertNull((new HmacMailStopTokens([self::NEW]))->read($sent));
    }

    public function testNoKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HmacMailStopTokens([]);
    }
}
