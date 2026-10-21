<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\Effect;
use App\Shared\Infrastructure\Effects\EffectsAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The answer says what the request did beyond its subject, within 1 KB of headers: `X-Live-Changes` may take 2 KB of
 * the 4 KB a proxy buffers by default, so past 1 KB the effects are folded to a count per kind, without figures.
 */
#[CoversClass(EffectsAnswer::class)]
final class EffectsAnswerTest extends TestCase
{
    public function testWhatTheRequestDidIsWrittenWithItsSubjectAndFigures(): void
    {
        $id = Uuid::fromString('0199a0c2-0000-7000-8000-000000000001');

        $header = EffectsAnswer::header([new Effect('product.price_followed', $id, ['previousPrice' => '18.4620']), new Effect('product.margin_moved', null)]);

        self::assertSame('[{"kind":"product.price_followed","id":"0199a0c2-0000-7000-8000-000000000001","params":{"previousPrice":"18.4620"}},{"kind":"product.margin_moved","id":null,"params":{}}]', $header, 'params is always an object, so the screen reads it one way');
    }

    public function testNothingDoneBeyondTheSubjectWritesNoHeader(): void
    {
        self::assertNull(EffectsAnswer::header([]));
    }

    public function testATextOutsideAsciiIsEscapedSinceAHeaderCarriesNoOther(): void
    {
        $header = EffectsAnswer::header([new Effect('x', null, ['name' => 'Vis à bois'])]);

        self::assertSame(1, preg_match('/^[\x20-\x7e]+$/', (string) $header));
        self::assertSame([['kind' => 'x', 'id' => null, 'params' => ['name' => 'Vis à bois']]], json_decode((string) $header, true));
    }

    public function testTooManyEffectsForAProxyAreFoldedToACountPerKindWithoutFigures(): void
    {
        $said = [];
        for ($i = 0; $i < 20; ++$i) {
            $said[] = new Effect('product.price_followed', Uuid::v7(), ['previousPrice' => '18.4620']);
        }
        $said[] = new Effect('product.margin_moved', Uuid::v7());

        $header = (string) EffectsAnswer::header($said);

        self::assertLessThanOrEqual(1024, \strlen($header));
        self::assertSame([
            ['kind' => 'product.price_followed', 'id' => null, 'params' => [], 'count' => 20],
            ['kind' => 'product.margin_moved', 'id' => null, 'params' => [], 'count' => 1],
        ], json_decode($header, true));
    }

    public function testEvenFoldedTheEffectsNeverOutgrowTheLimit(): void
    {
        $said = [];
        for ($i = 0; $i < 80; ++$i) {
            $said[] = new Effect(\sprintf('a.kind.named.at.some.length.%02d', $i), null);
        }

        $header = (string) EffectsAnswer::header($said);

        self::assertLessThanOrEqual(1024, \strlen($header));
        self::assertNotSame([], json_decode($header, true), 'as many kinds as fit are still said');
    }
}
