<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\SecurityTxt;
use PHPUnit\Framework\TestCase;

final class SecurityTxtTest extends TestCase
{
    private const string ORIGIN = 'https://app.twes.example';

    public function testTheFileNamesTheSecurityContactAndExpiresThirtyDaysAheadAtMidnightUtc(): void
    {
        $text = new SecurityTxt(self::ORIGIN)->render(
            ['security.email' => 'security@twes.example', 'publisher.name' => 'twes SAS'],
            new \DateTimeImmutable('2026-09-27 17:40', new \DateTimeZone('Europe/Paris')),
        );

        self::assertSame(
            "Contact: mailto:security@twes.example\n"
            ."Expires: 2026-10-27T00:00:00Z\n"
            ."Preferred-Languages: fr, en, ar\n"
            ."Canonical: https://app.twes.example/.well-known/security.txt\n"
            ."Policy: https://app.twes.example/legal/security\n",
            $text,
        );
    }

    /** RFC 9116 makes Contact mandatory, so a file without one would be invalid: none is served until it is filled in. */
    public function testWithoutASecurityContactThereIsNoFile(): void
    {
        self::assertNull(new SecurityTxt(self::ORIGIN)->render(['publisher.email' => 'hello@twes.example'], new \DateTimeImmutable()));
    }

    public function testATrailingSlashOnTheOriginIsNotDoubled(): void
    {
        $text = new SecurityTxt(self::ORIGIN.'/')->render(['security.email' => 's@twes.example'], new \DateTimeImmutable('2026-09-27'));

        self::assertStringContainsString("Canonical: https://app.twes.example/.well-known/security.txt\n", (string) $text);
    }

    /** A value is one line: a line break typed into the setting must not add a field of its own to the file. */
    public function testALineBreakInTheContactCannotAddAField(): void
    {
        $text = new SecurityTxt(self::ORIGIN)->render(
            ['security.email' => "s@twes.example\nExpires: 2099-01-01T00:00:00Z"],
            new \DateTimeImmutable('2026-09-27'),
        );

        self::assertNull($text);
    }
}
