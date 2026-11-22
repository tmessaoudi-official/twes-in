<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testItIsNormalisedToLowerCaseWithoutSurroundingSpace(): void
    {
        $email = Email::fromString("  Owner@Example.TEST \n");

        self::assertSame('owner@example.test', $email->value);
        self::assertSame('owner@example.test', (string) $email);
        self::assertTrue($email->equals(Email::fromString('owner@example.test')));
        self::assertFalse($email->equals(Email::fromString('other@example.test')));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at sign' => ['owner.example.test'];
        yield 'no domain' => ['owner@'];
        yield 'no local part' => ['@example.test'];
        yield 'space inside' => ['ow ner@example.test'];
        yield 'too long' => [str_repeat('a', 250).'@example.test'];
        // egulias/email-validator's RFCValidation accepts anything after a domain-literal (GHSA-9h5m-6mj8-8r36, no
        // fixed release), and symfony/mime's Address lets the comma tail through: every recipient we mail is this
        // value, so it alone keeps a second recipient or SMTP commands out of an address.
        yield 'commands after a domain-literal' => ["user@[127.0.0.1]>\r\nRSET\r\nRCPT TO:<attacker@evil.test>"];
        yield 'a second recipient after a domain-literal' => ['user@[127.0.0.1], attacker@evil.test'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedAddressIsRefused(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Email::fromString($raw);
    }
}
