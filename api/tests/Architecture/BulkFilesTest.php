<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Infrastructure\Http\BulkFile;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A support grant refuses every file carrying the company out whole by its mark, BulkFile, not by its path: a report's
 * export once escaped a path pattern naming only `/exports/`. Every action recording `export.downloaded` is such a
 * file, so each carries the mark, and every marked class answers a route, so the mark is never left on nothing.
 */
final class BulkFilesTest extends KernelTestCase
{
    public function testEveryExportDownloadedIsMarkedAndEveryMarkAnswersARoute(): void
    {
        $recording = [];
        $marked = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (1 !== preg_match('#^namespace ([^;]+);.*?^final (?:readonly )?class (\w+)( implements [^{]*)?#ms', $source, $match)) {
                continue;
            }
            $class = $match[1].'\\'.$match[2];
            if (str_contains($source, "'export.downloaded'")) {
                $recording[] = $class;
            }
            if (str_contains($match[3] ?? '', 'BulkFile')) {
                $marked[] = $class;
            }
        }
        self::assertGreaterThanOrEqual(3, \count($recording), 'the exports recording export.downloaded are found');
        foreach ($recording as $class) {
            self::assertTrue(is_subclass_of($class, BulkFile::class), $class.' records export.downloaded and is marked BulkFile');
        }

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routed = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            $controller = $route->getDefault('_controller');
            if (\is_string($controller)) {
                $routed[explode('::', $controller, 2)[0]] = true;
            }
        }
        self::assertGreaterThanOrEqual(\count($recording), \count($marked), 'the marked controllers are found');
        foreach ($marked as $class) {
            self::assertArrayHasKey($class, $routed, $class.' is marked BulkFile and answers a route');
        }
    }
}
