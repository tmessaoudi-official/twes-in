<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A test waiting for a `\LogicException` names which one. `\DomainException` extends it, so a bare expectation also
 * passes on every domain refusal the same input meets: the guard under test can vanish and the test stays green.
 * Every `expectException(\LogicException::class)` has an `expectExceptionMessage` (or `…Matches`) in its method, and
 * every `catch (\LogicException $x)` reads `$x` again after it; a refusal of the domain is waited for by its own class.
 */
final class NamedLogicRefusalsTest extends TestCase
{
    private const string TESTS = __DIR__.'/..';

    public function testEveryLogicExceptionATestWaitsForIsNamed(): void
    {
        $expected = 0;
        $caught = 0;
        $bare = [];
        $tests = (string) realpath(self::TESTS);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || __FILE__ === $file->getRealPath()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $where = substr($file->getPathname(), \strlen($tests) + 1);
            foreach (self::methods($code) as [$offset, $method]) {
                $line = static fn (int $at): int => substr_count($code, "\n", 0, $offset + $at) + 1;
                preg_match_all('/expectException\(\s*\\\\?LogicException::class\s*\)/', $method, $waits, \PREG_OFFSET_CAPTURE);
                foreach ($waits[0] as [, $at]) {
                    ++$expected;
                    if (!str_contains($method, 'expectExceptionMessage')) {
                        $bare[] = \sprintf('%s:%d expects a LogicException without its message', $where, $line($at));
                    }
                }
                preg_match_all('/catch\s*\(([^)]*)\)/', $method, $catches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
                foreach ($catches as [[$whole, $at], [$types]]) {
                    if (1 !== preg_match('/(?:^|\|)\s*\\\\?LogicException\b/', $types)) {
                        continue;
                    }
                    ++$caught;
                    if (1 !== preg_match('/\$(\w+)\s*$/', trim($types), $variable)) {
                        $bare[] = \sprintf('%s:%d catches a LogicException without reading it', $where, $line($at));
                    } elseif (1 !== preg_match('/\$'.$variable[1].'\b/', substr($method, $at + \strlen($whole)))) {
                        $bare[] = \sprintf('%s:%d catches a LogicException as $%s and never reads it', $where, $line($at), $variable[1]);
                    }
                }
            }
        }

        // The counts of today: a wrong directory, or a file read as one method, finds fewer and reds rather than passes.
        self::assertGreaterThanOrEqual(30, $expected, 'every expected LogicException was found');
        self::assertGreaterThanOrEqual(12, $caught, 'every caught LogicException was found');
        self::assertSame([], $bare, 'a LogicException waited for unnamed also passes on any domain refusal: name its message, or wait for its own class');
    }

    /** @return list<array{int, string}> each method's offset in the file and its text, up to the next method */
    private static function methods(string $code): array
    {
        preg_match_all('/\bfunction\s+\w+\s*\(/', $code, $found, \PREG_OFFSET_CAPTURE);
        $starts = array_column($found[0], 1);
        $methods = [];
        foreach ($starts as $index => $start) {
            $end = $starts[$index + 1] ?? \strlen($code);
            $methods[] = [$start, substr($code, $start, $end - $start)];
        }

        return $methods;
    }
}
