<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Realtime;

use PHPUnit\Framework\TestCase;

/**
 * An audit entry is said to the screens of its company; one with no company reaches its actor's own tabs, or nobody when
 * a worker writes it. A write told to nobody leaves every screen showing it stale with no error anywhere: the worker's
 * account deletions and company removals, an operator's account actions (workflow hunt, freshness E3, E4). Each kind
 * written with no company says here who it is for. What this reads is the call: a company handed as a variable that is
 * null at run time (a platform setting, an installation text) escapes it.
 */
final class AudienceTest extends TestCase
{
    /** Each kind written with no company, and who hears it. */
    private const array AUDIENCE = [
        'user' => 'a person\'s own account (factors, password, sessions, proofs, their deletion asked or cancelled), said to their own tabs; a sign-in that failed names nobody; an operator\'s action on an account and the worker\'s deletion reach no other screen, the accounts list read again when opened',
        'legal_text' => 'written by the operators, whose platform channel says only `app.realtime.platform_kinds`: the legal pages read it when opened',
        'company' => 'a company removed by the worker, its sandbox or its retention over: no member is left, the operators\' list read again when opened',
    ];

    public function testEveryAuditEntryWithNoCompanySaysWhoHearsIt(): void
    {
        $root = \dirname(__DIR__, 4).'/api/src';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        $constants = [];
        foreach ($files as $file) {
            preg_match_all("/const string ([A-Z_]+) = '([^']*)'/", (string) file_get_contents($file), $found, \PREG_SET_ORDER);
            foreach ($found as [, $name, $value]) {
                $constants[basename($file, '.php').'::'.$name] = $value;
            }
        }
        $calls = 0;
        $unheard = [];
        foreach ($files as $file) {
            foreach ($this->entries((string) file_get_contents($file)) as $arguments) {
                ++$calls;
                if (!$this->withoutCompany($arguments)) {
                    continue;
                }
                $kind = $arguments[0] ?? '';
                if (1 === preg_match('/^(\w+)::([A-Z_]+)$/', $kind, $constant)) {
                    $class = \in_array($constant[1], ['self', 'static'], true) ? basename($file, '.php') : $constant[1];
                    $kind = $constants["$class::{$constant[2]}"] ?? $kind;
                }
                $unheard[trim($kind, "'")][] = substr($file, \strlen($root) + 1);
            }
        }

        self::assertGreaterThanOrEqual(120, $calls, 'the audit entries are read');
        foreach ($unheard as $kind => $where) {
            self::assertArrayHasKey($kind, self::AUDIENCE, \sprintf('%s write %s with no company, told to nobody else: hand it its company, or say here who hears it', implode(', ', array_unique($where)), $kind));
        }
        foreach (array_keys(self::AUDIENCE) as $kind) {
            self::assertArrayHasKey($kind, $unheard, "no $kind is written with no company any more: take it off the list");
        }
    }

    /**
     * The arguments of each `new AuditEntry(…)`, as written, read through PHP's own tokens.
     *
     * @return list<list<string>>
     */
    private function entries(string $source): array
    {
        $tokens = token_get_all($source);
        $entries = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!\is_array($tokens[$i]) || \T_NEW !== $tokens[$i][0]) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && \is_array($tokens[$j]) && \T_WHITESPACE === $tokens[$j][0]) {
                ++$j;
            }
            if (!\is_array($tokens[$j] ?? null) || 'AuditEntry' !== $tokens[$j][1]) {
                continue;
            }
            $arguments = [];
            $current = '';
            $depth = 0;
            for ($k = $j + 1; $k < $count; ++$k) {
                $text = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if (\in_array($text, ['(', '[', '{'], true)) {
                    if (++$depth > 1) {
                        $current .= $text;
                    }
                    continue;
                }
                if (\in_array($text, [')', ']', '}'], true)) {
                    if (0 === --$depth) {
                        break;
                    }
                    $current .= $text;
                    continue;
                }
                if (',' === $text && 1 === $depth) {
                    $arguments[] = trim($current);
                    $current = '';
                    continue;
                }
                $current .= $text;
            }
            $arguments[] = trim($current);
            $entries[] = array_values(array_filter($arguments, static fn (string $argument): bool => '' !== $argument));
        }

        return $entries;
    }

    /** @param list<string> $arguments */
    private function withoutCompany(array $arguments): bool
    {
        $positional = [];
        foreach ($arguments as $argument) {
            if (1 === preg_match('/^companyId\s*:\s*(.+)$/s', $argument, $named)) {
                return 'null' === trim($named[1]);
            }
            if (1 !== preg_match('/^\w+\s*:(?!:)/', $argument)) {
                $positional[] = $argument;
            }
        }

        return !isset($positional[5]) || 'null' === $positional[5];
    }
}
