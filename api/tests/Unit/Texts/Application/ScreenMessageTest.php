<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Texts\Application;

use App\Texts\Application\ScreenMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A screen text the API writes is filled in as the screen fills it: the web's own vectors, read by both. */
final class ScreenMessageTest extends TestCase
{
    /** @param array<string, int> $params */
    #[DataProvider('vectors')]
    public function testFillsAScreenTextAsTheScreenDoes(string $language, string $text, array $params, string $said): void
    {
        self::assertSame($said, ScreenMessage::say($text, $language, $params));
    }

    /** @return iterable<string, array{string, string, array<string, int>, string}> */
    public static function vectors(): iterable
    {
        $read = json_decode((string) file_get_contents(\dirname(__DIR__, 5).'/web/src/app/shared/i18n/screen-text-vectors.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($read);
        self::assertIsArray($read['vectors'] ?? null);
        foreach ($read['vectors'] as $index => $vector) {
            self::assertIsArray($vector);
            ['language' => $language, 'text' => $text, 'params' => $params, 'said' => $said] = $vector;
            self::assertIsString($language);
            self::assertIsString($text);
            self::assertIsArray($params);
            self::assertIsString($said);
            $counts = [];
            foreach ($params as $name => $value) {
                self::assertIsInt($value);
                $counts[(string) $name] = $value;
            }

            yield $index.' '.$language => [$language, $text, $counts, $said];
        }
    }
}
