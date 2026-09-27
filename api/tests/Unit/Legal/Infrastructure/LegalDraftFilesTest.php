<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Infrastructure;

use App\Legal\Application\LegalDraft;
use App\Legal\Application\LegalSettings;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Infrastructure\Drafts\LegalDraftFiles;
use PHPUnit\Framework\TestCase;

final class LegalDraftFilesTest extends TestCase
{
    public function testEveryShippedDraftNamesAPageAndALanguageAndCookiesShipsInAllThree(): void
    {
        $drafts = (new LegalDraftFiles(__DIR__.'/../../../../resources/legal'))->all();

        $cookies = array_map(
            static fn (LegalDraft $draft) => $draft->language,
            array_values(array_filter($drafts, static fn (LegalDraft $draft) => LegalPage::Cookies === $draft->page)),
        );
        self::assertEqualsCanonicalizing(LegalLanguage::cases(), $cookies);
        foreach ($drafts as $draft) {
            self::assertNotSame('', trim($draft->body), $draft->page->value.'.'.$draft->language->value.' is empty');
        }
    }

    public function testEveryPageShipsADraftInEveryLanguage(): void
    {
        $shipped = array_map(
            static fn (LegalDraft $draft) => $draft->page->value.'.'.$draft->language->value,
            self::drafts(),
        );
        $expected = [];
        foreach (LegalPage::cases() as $page) {
            foreach (LegalLanguage::cases() as $language) {
                $expected[] = $page->value.'.'.$language->value;
            }
        }
        self::assertEqualsCanonicalizing($expected, $shipped);
    }

    /**
     * The reader's language prevails (§ 7), so the three versions of a page must say the same thing: the same headings
     * at the same levels, the same number of list items and the same facts filled in. A lawyer checks the words; this
     * catches a section or a fact dropped from one language.
     */
    public function testThePagesThreeLanguagesHaveTheSameShapeAndTheSameFacts(): void
    {
        $shapes = [];
        foreach (self::drafts() as $draft) {
            $shapes[$draft->page->value][$draft->language->value] = self::shapeOf($draft->body);
        }
        foreach ($shapes as $page => $byLanguage) {
            $french = $byLanguage['fr'] ?? null;
            foreach ($byLanguage as $language => $shape) {
                self::assertSame($french, $shape, $page.': '.$language.' differs from fr');
            }
        }
    }

    public function testEveryPlaceholderADraftNamesIsOneTheOperatorFillsIn(): void
    {
        $used = [];
        foreach (self::drafts() as $draft) {
            preg_match_all('/\{\{\s*([a-z][a-z_.]*)\s*\}\}/', $draft->body, $matches);
            foreach ($matches[1] as $placeholder) {
                $used[$placeholder] = true;
                self::assertContains($placeholder, LegalSettings::PLACEHOLDERS, $draft->page->value.'.'.$draft->language->value);
            }
        }
        // Every fact the operator can fill in is named by some page, or it is a setting nobody reads.
        self::assertEqualsCanonicalizing(LegalSettings::PLACEHOLDERS, array_keys($used));
    }

    /** @return list<LegalDraft> */
    private static function drafts(): array
    {
        return (new LegalDraftFiles(__DIR__.'/../../../../resources/legal'))->all();
    }

    /** @return array{headings: list<string>, items: int, facts: list<string>} */
    private static function shapeOf(string $body): array
    {
        preg_match_all('/^(#{1,6}) /m', $body, $headings);
        preg_match_all('/^\s*(?:[-*]|\d+\.) /m', $body, $items);
        preg_match_all('/\{\{\s*([a-z][a-z_.]*)\s*\}\}/', $body, $facts);
        $named = array_values(array_unique($facts[1]));
        sort($named);

        return ['headings' => $headings[1], 'items' => \count($items[0]), 'facts' => $named];
    }

    public function testAFileNamingNoPageOrLanguageIsRefusedRatherThanSkipped(): void
    {
        $directory = sys_get_temp_dir().'/legal-drafts-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/cookie.fr.md', 'typo in the page');
        try {
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('cookie.fr.md');
            (new LegalDraftFiles($directory))->all();
        } finally {
            unlink($directory.'/cookie.fr.md');
            rmdir($directory);
        }
    }
}
