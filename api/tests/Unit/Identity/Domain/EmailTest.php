<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
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
    }

    #[DataProvider('malformed')]
    public function testAMalformedAddressIsRefused(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Email::fromString($raw);
    }
}
