<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A response whose type is a value rather than a word of the code (a person's upload names its own: an attachment, a
 * photo, a logo) is shown, never run: it says `nosniff` and a sandboxed Content-Security-Policy, so an HTML or SVG file
 * someone uploaded opens with no script, no request and no form of the API's origin; or it is handed over as an
 * attachment, which the browser saves rather than opens. Found by its `'Content-Type' =>` taking anything but a quoted
 * literal, as every answer of a stored file writes it.
 */
final class StoredFilesShownNeverRunTest extends TestCase
{
    public function testEveryAnswerWhoseTypeIsAValueIsSandboxedOrSaved(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (0 === preg_match_all('#[\'"]Content-Type[\'"]\s*(?:=>|,)\s*+(?![\'"])#', $source)) {
                continue;
            }
            $where = substr($file->getPathname(), \strlen(\dirname(__DIR__, 2)) + 1);
            $found[] = $where;
            $saved = str_contains($source, 'DISPOSITION_ATTACHMENT') && !str_contains($source, 'DISPOSITION_INLINE');
            if ($saved) {
                continue;
            }
            self::assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $source, $where.' answers a type it was handed: it says nosniff');
            self::assertMatchesRegularExpression("#'Content-Security-Policy' => \"default-src 'none';[^\"]*\\bsandbox\"#", $source, $where.' answers a type it was handed: its policy is default-src none and sandbox');
        }
        // Today's: three attachments, a photo, a logo, the pairing's code, and the three spreadsheets handed over.
        self::assertGreaterThanOrEqual(9, \count($found), 'the answers whose type is a value are found: '.implode(', ', $found));
    }
}
