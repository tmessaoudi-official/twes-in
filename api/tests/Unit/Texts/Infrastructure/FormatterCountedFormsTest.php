<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Texts\Infrastructure;

use App\Texts\Infrastructure\Translation\FormatterCountedForms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Formatter\MessageFormatter;

/**
 * A text with forms is kept only when every whole count, however large, finds one: Symfony throws on a count no
 * interval holds and no form of its language's position stands for, which a mail or a printed document would do at
 * that count alone, long after the save.
 */
final class FormatterCountedFormsTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function texts(): iterable
    {
        yield 'one form' => ['À %days% jours', 'fr', true];
        yield 'singular and plural' => ['À %days% jour|À %days% jours', 'fr', true];
        yield 'intervals reaching every count' => ['{0} À réception|{1} À %days% jour|]1,Inf[ À %days% jours', 'fr', true];
        yield 'an interval and the forms beyond it' => ['{0} À réception|À %days% jour|À %days% jours', 'fr', true];
        yield 'one form in Arabic' => ['الأجل بالأيام: %days%', 'ar', true];
        yield 'Arabic six forms' => ['a|b|c|d|e|f', 'ar', true];
        yield 'a last interval closed at 100' => ['{0} À réception|{1} À %days% jour|]1,100] À %days% jours', 'fr', false];
        yield 'a range closed at 100' => ['[0,100] Relance %days%', 'fr', false];
        yield 'a set of the counts once tried' => ['{0,1,2,5,21,100} À %days% jours', 'fr', false];
        yield 'a gap between a set and an open interval' => ['{0,1,2,5,21,100} a|[101,Inf[ b', 'fr', false];
        yield 'a standard form above a closed interval' => ['[0,100] a|b', 'en', false];
        yield 'a gap inside intervals' => ['[0,5] a|[10,Inf[ b', 'fr', false];
        yield 'a count below the first interval' => [']1,Inf[ b', 'fr', false];
        yield 'two forms in Arabic' => ['a|b', 'ar', false];
        // An interval over 11 to 99 days leaves Arabic's fifth form to 111 and beyond, and its sixth to 100 to 102.
        yield 'Arabic six forms beside an interval to 99' => ['a|b|c|d|[11,99] e|f|g', 'ar', true];
        yield 'Arabic five forms beside an interval to 99' => ['a|b|c|d|[11,99] e|f', 'ar', false];
    }

    #[DataProvider('texts')]
    public function testEveryCountChoosesAFormOrTheTextIsRefused(string $text, string $language, bool $kept): void
    {
        self::assertSame($kept, (new FormatterCountedForms(new MessageFormatter()))->everyCountChooses($text, $language));
    }
}
