<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Infrastructure;

use App\Legal\Application\LegalDraft;
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
