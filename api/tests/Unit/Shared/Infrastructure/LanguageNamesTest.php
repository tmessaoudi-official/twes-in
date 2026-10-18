<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Intl\LanguageNames;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageNamesTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function written(): iterable
    {
        yield 'the tag' => ['en', 'en'];
        yield 'the tag in capitals' => [' EN ', 'en'];
        yield 'named in French' => ['Anglais', 'en'];
        yield 'named in French, without its accent' => ['FRANCAIS', 'fr'];
        yield 'named in French, with it' => ['français', 'fr'];
        yield 'named in English' => ['french', 'fr'];
        yield 'named in itself' => ['English', 'en'];
        yield 'a language documents are not printed in' => ['allemand', null];
        yield 'a language only the legal pages are written in' => ['arabe', null];
        yield 'nothing' => ['', null];
    }

    /** A spreadsheet names a customer's language as a person writes it, in any language the screens speak. */
    #[DataProvider('written')]
    public function testALanguageIsReadAsAPersonWritesIt(string $cell, ?string $tag): void
    {
        $languages = ShippedLanguages::catalogue();

        self::assertSame($tag, (new LanguageNames($languages))->documentLanguageOf($cell));
    }
}
