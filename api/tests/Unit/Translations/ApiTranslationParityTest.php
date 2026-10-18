<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Translations;

use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** What the API prints or mails exists in every language it speaks, key for key (the SPA's own parity test covers its files). */
final class ApiTranslationParityTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../translations';

    /** The interface's languages mail, the documents' print: the catalogue says which, and each needs every key. */
    public function testEveryDomainHasTheSameKeysInEveryLanguageTheCatalogueShips(): void
    {
        $languages = ShippedLanguages::catalogue();
        $domains = array_map(static fn (string $path): string => basename($path, '.fr.yaml'), glob(self::DIRECTORY.'/*.fr.yaml') ?: []);
        self::assertContains('pdf', $domains);
        self::assertSame('fr', $languages->default());

        foreach ($domains as $domain) {
            foreach (array_unique([...$languages->interface(), ...$languages->documents()]) as $language) {
                self::assertFileExists(self::DIRECTORY."/$domain.$language.yaml");
                self::assertSame(self::keys("$domain.fr.yaml"), self::keys("$domain.$language.yaml"), "$domain.$language");
            }
        }
    }

    /**
     * Every key a printed document names exists in its domain, or the document prints the key itself: a statement once
     * printed « company.currency » beside its period. The templates name their domain through `t`, which Symfony's own
     * `debug:translation` cannot follow, so this reads `t` as the `pdf` domain each template sets it to.
     */
    public function testEveryKeyAPrintedDocumentNamesExistsInItsDomain(): void
    {
        $missing = [];
        $found = 0;
        foreach (glob(__DIR__.'/../../../templates/pdf/*.twig') ?: [] as $template) {
            preg_match_all("/'([\\w.]+)'\\|trans\\(.*?,\\s*(t|'\\w+'),\\s*locale\\)/", (string) file_get_contents($template), $calls, \PREG_SET_ORDER);
            foreach ($calls as [, $key, $domain]) {
                ++$found;
                $domain = 't' === $domain ? 'pdf' : trim($domain, "'");
                if (!\in_array($key, self::keys("$domain.fr.yaml"), true)) {
                    $missing[] = basename($template).": $domain $key";
                }
            }
        }

        self::assertGreaterThan(100, $found, 'the keys the templates name are found, so an empty set cannot pass');
        self::assertSame([], $missing);
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
            self::assertIsArray($node, $path);
            array_walk_recursive($node, static function (mixed $text) use (&$loose): void {
                if (\is_string($text) && 1 === preg_match('/(?<=\S)[ \x{00A0}][;?!]|(?<=\S)[ \x{202F}]:(?=\s|$)|« | »/u', $text)) {
                    $loose[] = $text;
                }
            });
        }

        self::assertSame([], $loose);
    }

    public function testFrenchElidesWithTheTypographicApostropheNeverAStraightOne(): void
    {
        $straight = [];
        foreach (glob(self::DIRECTORY.'/*.fr.yaml') ?: [] as $path) {
            $node = Yaml::parseFile($path);
            self::assertIsArray($node, $path);
            array_walk_recursive($node, static function (mixed $text) use (&$straight): void {
                if (\is_string($text) && 1 === preg_match("/\\p{L}'\\p{L}/u", $text)) {
                    $straight[] = $text;
                }
            });
        }

        self::assertSame([], $straight);
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
