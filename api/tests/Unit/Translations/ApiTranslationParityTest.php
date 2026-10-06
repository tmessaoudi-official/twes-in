<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** What the API prints or mails exists in both product languages, key for key (the SPA's own parity test covers its files). */
final class ApiTranslationParityTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../translations';

    public function testEveryDomainHasTheSameKeysInFrenchAndEnglish(): void
    {
        $domains = array_map(static fn (string $path): string => basename($path, '.fr.yaml'), glob(self::DIRECTORY.'/*.fr.yaml') ?: []);
        self::assertContains('pdf', $domains);

        foreach ($domains as $domain) {
            self::assertFileExists(self::DIRECTORY."/$domain.en.yaml");
            self::assertSame(self::keys("$domain.fr.yaml"), self::keys("$domain.en.yaml"), $domain);
        }
    }

    /**
     * French holds « : » to its word with a no-break space and « ; ? ! » with a narrow one, and « » hold what they
     * quote the same way, so a printed line or a mail never starts with a mark (audit 2026-10-06, N-b).
     */
    public function testFrenchPunctuationIsHeldToItsWordByTheRightNoBreakSpace(): void
    {
        $loose = [];
        foreach (glob(self::DIRECTORY.'/*.fr.yaml') ?: [] as $path) {
            $node = Yaml::parseFile($path);
            array_walk_recursive($node, static function (mixed $text) use (&$loose): void {
                if (\is_string($text) && 1 === preg_match('/(?<=\S)[ \x{00A0}][;?!]|(?<=\S)[ \x{202F}]:(?=\s|$)|« | »/u', $text)) {
                    $loose[] = $text;
                }
            });
        }

        self::assertSame([], $loose);
    }

    /** @return list<string> */
    private static function keys(string $file): array
    {
        $keys = self::flatten(Yaml::parseFile(self::DIRECTORY.'/'.$file), '');
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private static function flatten(mixed $node, string $prefix): array
    {
        if (!\is_array($node)) {
            return [$prefix];
        }
        $keys = [];
        foreach ($node as $key => $child) {
            $keys = [...$keys, ...self::flatten($child, '' === $prefix ? (string) $key : $prefix.'.'.$key)];
        }

        return $keys;
    }
}
