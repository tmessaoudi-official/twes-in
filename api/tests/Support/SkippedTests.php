<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The tests a suite skipped, read from its JUnit log and held to `api/tests/skips-allowed.txt`: PHPUnit exits 0 on a
 * skip, so a case that could not run would otherwise read as green (docs/SPEC.md § 7, a cloud session never silently
 * worse than the computer). On CI a skip no line names is a warning on the run, as a schema source that did not answer
 * is (`scripts/fiscal/fetch-xsd.sh`); anywhere else it reds a passing run.
 */
final class SkippedTests
{
    public const string ALLOWED = 'api/tests/skips-allowed.txt';

    /**
     * Each skipped test no line of `$allowed` names, as `Class::method` (with its data set when it has one). A line names
     * a test by `Class::method`, then why it may skip; it covers every data set of that method.
     *
     * @return list<string>
     */
    public static function unexpected(string $junit, string $allowed): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $log = simplexml_load_string($junit);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (false === $log) {
            throw new \UnexpectedValueException('The JUnit log could not be read: the skipped tests cannot be told.');
        }

        $named = [];
        foreach (preg_split('/\R/', $allowed) ?: [] as $line) {
            $line = trim($line); // trim: a line of a file of this tree, never typed text
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $named[] = preg_split('/\s+/', $line, 2)[0] ?? $line;
        }

        $unexpected = [];
        foreach ($log->xpath('//testcase[skipped]') ?: [] as $case) {
            $test = (string) $case['class'].'::'.(string) $case['name'];
            $method = preg_replace('/ with data set .*$/s', '', $test) ?? $test;
            if (!\in_array($method, $named, true)) {
                $unexpected[] = $test;
            }
        }

        return $unexpected;
    }

    /**
     * The run's exit and what to print, given its unexpected skips, its own exit and whether it runs on CI.
     *
     * @param list<string> $unexpected
     *
     * @return array{int, list<string>}
     */
    public static function verdict(array $unexpected, int $status, bool $ci): array
    {
        if ([] === $unexpected) {
            return [$status, []];
        }
        $fix = 'name it in '.self::ALLOWED.' with why, or make it run';
        if ($ci) {
            return [$status, array_map(static fn (string $test): string => "::warning title=Test skipped::$test was skipped: $fix\n", $unexpected)];
        }
        $said = [\sprintf("%d test(s) skipped that %s does not name: a skipped case is not a passing one (%s):\n", \count($unexpected), self::ALLOWED, $fix)];
        foreach ($unexpected as $test) {
            $said[] = "  $test\n";
        }

        return [0 === $status ? 1 : $status, $said];
    }

    /**
     * A run that left no JUnit log: its skips cannot be told, so a passing one is not taken as passing, CI included.
     *
     * @return array{int, list<string>}
     */
    public static function withoutLog(int $status): array
    {
        return [0 === $status ? 1 : $status, ["The suite's run left no JUnit log: which tests it skipped cannot be told, so it is not read as passing.\n"]];
    }
}
