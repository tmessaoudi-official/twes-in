<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanning\Infrastructure;

use App\Scanning\Infrastructure\Http\PhoneAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneAddressTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function addresses(): iterable
    {
        yield 'unset: the tab uses its own address' => ['', null];
        yield 'blank' => ['  ', null];
        yield 'an https origin' => ['https://192.168.1.20:8443', 'https://192.168.1.20:8443'];
        yield 'its trailing slash trimmed' => ['https://192.168.1.20:8443/', 'https://192.168.1.20:8443'];
        yield 'plain http, for a stack behind its own TLS' => ['http://phone.lan', 'http://phone.lan'];
        yield 'a path is not an origin' => ['https://192.168.1.20:8443/pair', null];
        yield 'not a URL' => ['192.168.1.20', null];
        yield 'another scheme' => ['javascript:alert(1)', null];
    }

    #[DataProvider('addresses')]
    public function testOnlyAnOriginIsAnAddress(string $configured, ?string $expected): void
    {
        self::assertSame($expected, PhoneAddress::of($configured));
    }
}
