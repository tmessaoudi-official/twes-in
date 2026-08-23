<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `DatabaseRequirement`'s absolute claim, ENFORCED rather than asserted in prose.
 *
 * That class states *"THERE IS NO LEGITIMATE SKIP IN THIS SUITE"*, and it was false for eight rounds: round
 * 15 applied the fail-rather-than-skip ruling to `superuserConnection()` and nowhere else, leaving seven
 * guards in `TenantIsolationTest` that skipped on a missing env var and one that skipped on an unreachable
 * database. A CI setting the superuser pair but omitting the probe-role names reported green with four
 * mutant-killing security cases unexercised — measured, not inferred: stripping
 * `TWES_TEST_DB_PROBE_OWNER_ROLE` from the configuration produced `OK, but some tests were skipped!` and
 * exit 0 with three of them silent.
 *
 * Converting those eight made the claim TRUE. This test is what makes it STAY true, and it exists because
 * `CLAUDE.md` § Gotchas records the same lesson from four separate directions — a guard on one write path, a
 * meta-gate reporting 33/33 for a gate that detected nothing, a permission nothing consulted, a control
 * asserted in three documents and enforced nowhere: **a rule enforced by memory is not a rule.** The eight
 * were not written by someone ignoring the invariant; they were written before it existed and survived every
 * later reading of the file.
 *
 * Scope is the INTEGRATION suite only, which is the scope of the claim. The `unit` suite carries one skip
 * (`unshare(CLONE_NEWPID)` unavailable in this container) that is openly reasoned and out of scope here.
 *
 * Enumeration is `git ls-files`, never a recursive walk — § Gotchas 2026-07-31: a parallel certification
 * round places reviewer worktrees INSIDE the working tree, so a walk reads several checkouts at once and a
 * gate that does it fails with an "actual" list that is not wrong about this repository, it is reading four.
 *
 * **This file is scanned like every other, with no exemption**, because § Gotchas already records that an
 * exemption inside a cross-check is where the drift hides. That is possible only because the method name is
 * ASSEMBLED below rather than written, so no literal call spelling appears here — the alternative, excluding
 * itself by path, is the self-referential guard shape § Gotchas records against the `global-stack-lead-dev`
 * handover script.
 */
final class NoLegitimateSkipTest extends TestCase
{
    /**
     * The forbidden method name, assembled so that this file carries no literal call spelling of it.
     *
     * Without this, the scan below would report its own source and the only remedies would be an exemption
     * or a self-reference — both of which this class's docblock argues against.
     */
    private const string FORBIDDEN = 'markTest' . 'Skipped';

    /**
     * Every tracked file in the integration suite, scanned for a CALL rather than a MENTION.
     *
     * The distinction is load-bearing: `DatabaseRequirement` and `superuserConnection()` both name the method
     * in prose while documenting why it is refused, and a scan that could not tell those apart from a call
     * would have to exempt the two files that state the rule.
     */
    public function testTheIntegrationSuiteContainsNoSkip(): void
    {
        $files = self::trackedIntegrationFiles();

        // ANTI-VACUITY. A scan that reached no files reports the same clean verdict as a clean suite, which
        // is the shape this whole class exists to refuse. The floor is deliberately far below the real
        // count so that adding or removing a file never turns this into a maintenance chore.
        self::assertGreaterThan(
            5,
            \count($files),
            'The scan found almost no files, so its clean verdict means nothing. Check that this is a git '
            . 'work tree and that the integration suite is tracked.',
        );

        $offenders = [];

        foreach ($files as $relativePath => $absolutePath) {
            $contents = file_get_contents($absolutePath);
            self::assertIsString($contents, $relativePath . ' could not be read, so it went unscanned.');

            if (1 === preg_match(self::callPattern(), $contents, $match, \PREG_OFFSET_CAPTURE)) {
                $offenders[] = \sprintf(
                    '%s:%d',
                    $relativePath,
                    // +1 because a file's first line is 1 and substr_count returns the number of newlines
                    // BEFORE the offset.
                    substr_count(substr($contents, 0, (int) $match[0][1]), "\n") + 1,
                );
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "%s is called in the integration suite, and there is no legitimate skip in it.\n\n"
            . "  %s\n\n"
            . 'A skipped test reports OK while the control it carries does not run, and this suite carries '
            . 'the tenancy proof — the thing standing between this product and a reportable cross-tenant '
            . 'breach. Fail instead, with a message naming the missing variable, the control left '
            . 'unexercised, and the command that provides it: see DatabaseRequirement, and '
            . "TenantIsolationTest::superuserConnection() for the precedent.\n\n"
            . 'If a case genuinely cannot run somewhere, say what it would TAKE (CLAUDE.md § Gotchas '
            . '2026-07-30: never record a coverage gap as an impossibility). Four such claims in this '
            . 'repository were refuted in one session, and every one hid a real defect.',
            self::FORBIDDEN,
            implode("\n  ", $offenders),
        ));
    }

    /**
     * The scan can actually SEE a skip — the other half, without which a clean verdict proves nothing.
     *
     * `CLAUDE.md` § Gotchas records this twice over: `test-gates.sh` reported 33/33 for a gate that detected
     * nothing, and `PERMISSIVE_FOR_FONT_ASSETS` was declared, documented and consulted by no code path while
     * every case stayed green. A pattern that matches nothing and a suite that contains nothing are
     * indistinguishable from the verdict alone.
     *
     * The spellings are the ones PHPUnit accepts and a developer plausibly writes, INCLUDING the whitespace a
     * formatter can introduce: PHP permits spaces around both `::` and the opening parenthesis, and a pattern
     * anchored on the exact spelling in use today is the enumerate-the-forbidden-value shape that defeated
     * `worker-mode-blocked.sh` three times. Each spelling is ASSEMBLED below rather than written out, for the
     * same reason the constant is — and this paragraph learned that the hard way: it spelled one of them
     * literally as an illustration, and the scan reported this file, correctly. Which was also the moment the
     * scan proved it covers itself, since the run before this file was `git add`ed did not see it at all —
     * `git ls-files` lists TRACKED paths, so a brand-new test file is invisible to its own rule until staged
     * (§ Gotchas 2026-08-06: the gates split on exactly that flag).
     */
    public function testTheScanWouldSeeASkip(): void
    {
        $skip = self::FORBIDDEN;

        foreach ([
            'plain static call' => '        self::' . $skip . '(\'x\');',
            'late static binding' => '        static::' . $skip . '(\'x\');',
            'instance call' => '        $this->' . $skip . '(\'x\');',
            'spaced by a formatter' => '        self :: ' . $skip . ' (\'x\');',
            'broken across lines' => "        \$this\n            ->" . $skip . "(\n'x',\n);",
        ] as $description => $line) {
            self::assertSame(
                1,
                preg_match(self::callPattern(), $line),
                'The scan cannot see a ' . $description . ', so its clean verdict does not cover one.',
            );
        }
    }

    /**
     * And it does NOT fire on the prose that documents the rule — the direction that decides whether this
     * class is usable at all.
     *
     * Both files stating the invariant name the method in running text. A scan reporting them would have to
     * be answered with an exemption, and § Gotchas records that an exemption inside a cross-check is exactly
     * where the drift hides: `THIRD-PARTY-NOTICES.md` was exempted from the closed-list half of a licence
     * cross-check on reasonable-sounding grounds and was contradicting the gate in its own rule statement.
     */
    public function testTheScanDoesNotFireOnProseAboutTheRule(): void
    {
        $skip = self::FORBIDDEN;

        foreach ([
            'a docblock line' => ' * worst outcome available."* The suite contradicted that: both helpers '
                . 'called `' . $skip . '()` on any',
            'a bare mention' => '// ' . $skip . ' is refused here.',
            'a sentence naming it' => ' * It returned null and every caller called `' . $skip . '()` until '
                . 'round 15.',
        ] as $description => $line) {
            self::assertSame(
                0,
                preg_match(self::callPattern(), $line),
                'The scan fires on ' . $description . ', so the two files that STATE this rule would have '
                . 'to be exempted from it.',
            );
        }
    }

    /**
     * A call, not a mention: a receiver, an operator, the name, and an open parenthesis.
     *
     * Whitespace is permitted at every join because PHP permits it there — the point is to match what the
     * language accepts rather than what today's formatter happens to emit.
     */
    private static function callPattern(): string
    {
        return '/(?:self|static|parent|\$this)\s*(?:::|->)\s*' . self::FORBIDDEN . '\s*\(/';
    }

    /**
     * @return array<string, string> tracked relative path => absolute path
     */
    private static function trackedIntegrationFiles(): array
    {
        $root = \dirname(__DIR__, 3);

        $command = \sprintf(
            'git -C %s ls-files -z -- %s',
            escapeshellarg($root),
            escapeshellarg('api/tests/Integration'),
        );

        $output = shell_exec($command);
        self::assertIsString($output, 'git ls-files produced nothing, so nothing was scanned.');

        $files = [];

        foreach (array_filter(explode("\0", $output), static fn(string $p): bool => '' !== $p) as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $files[$path] = $root . '/' . $path;
        }

        return $files;
    }
}
