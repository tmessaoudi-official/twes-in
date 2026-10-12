<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Infrastructure\Http\RoutedPath;
use PHPUnit\Framework\TestCase;

/**
 * Whatever decides by a request's path reads it through RoutedPath, decoded as the router decodes it: the raw value lets
 * a percent-escaped spelling of a guarded path past the guard to the very route it guards.
 */
final class RoutedPathTest extends TestCase
{
    public function testOnlyRoutedPathReadsTheRawPath(): void
    {
        $source = \dirname(__DIR__, 2).'/src';
        $allowed = (new \ReflectionClass(RoutedPath::class))->getFileName();
        $readers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || $file->getPathname() === $allowed) {
                continue;
            }
            if (1 === preg_match('/->(getPathInfo|getRequestUri|getBaseUrl)\(/', (string) file_get_contents($file->getPathname()))) {
                $readers[] = substr($file->getPathname(), \strlen($source) + 1);
            }
        }

        self::assertSame([], $readers, 'read the path through RoutedPath::of()');
    }
}
