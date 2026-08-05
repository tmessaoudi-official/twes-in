<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * NO ORPHANED DOCBLOCKS: two doc comments with no declaration between them means the first documents nothing.
 *
 * WHY THIS EXISTS, and why it is a gate rather than a habit. A docblock's POSITION is part of its truth: PHP
 * attaches it to the next declaration, so when two appear in a row the first one silently stops describing
 * anything. Nothing else here can see that. `php -l` is happy — comments are comments; `php-cs-fixer` is happy —
 * it reformats doc comments but does not ask what they attach to [Verified: it reported `Found 0 of 69 files that
 * can be fixed` over a tree carrying seven]; and PHPStan would only catch the subset where the orphaning also
 * deletes a `@param`/`@return` generic, which is a side effect rather than the defect.
 *
 * It was filed by three successive certification rounds before becoming a gate, and round 16's own FIX created a
 * fresh instance — moving a corrected docblock above a different docblock while the superseded text stayed
 * attached to the method, so both were wrong at once.
 *
 * **WHY THIS IS A TOKENIZER PASS, having started as a two-line `awk` positional rule.** That rule required the
 * closing delimiter and the opening `/**` to be alone on immediately adjacent lines, and round 18 showed it
 * missed FOUR of five genuine shapes — every one confirmed orphaned by `ReflectionMethod::getDocComment()`:
 *
 *   1. a BLANK LINE between the two blocks — the most natural way anyone would author them;
 *   2. an `#[Attribute]` between them;
 *   3. a single-line second block (`/** Attached. *`+`/`);
 *   4. `*`+`/ /**` on ONE line — which the old comment asserted "is not this defect". That was simply FALSE, and
 *      a false impossibility used to justify a blind spot is the shape CLAUDE.md § Gotchas records five times;
 *   5. an orphan before a `const` or an `enum` case rather than a method.
 *
 * The lesson is the general one: a positional rule enforces one SPELLING of a rule, and the defect is not
 * positional at all. `token_get_all()` answers the token question directly: two `T_DOC_COMMENT`s with nothing
 * but whitespace, ordinary comments or an attribute between them. All five shapes above collapse into that one
 * rule, and so does any further shape of the SAME kind.
 *
 * **WHAT THIS GATE DOES NOT CATCH, stated because the previous wording claimed it caught everything.** That
 * wording — *"the defect is … 'this doc comment attaches to no declaration' … and so does any sixth nobody has
 * thought of yet"* — named a defect strictly WIDER than the rule implemented, which is the same false
 * universality round 18 filed against this file's predecessor. The rule fires on a RUN OF TWO doc comments. A
 * doc comment that attaches to nothing with no second one after it is invisible to it: one before a class's
 * closing brace, one before a plain statement, one before a `return` inside a method body. [Verified by
 * reflection: all three attach to nothing; the gate reports `violations=0`.]
 *
 * That is deliberate for now rather than owed. Catching them means asking "is the next significant token a
 * DECLARATION", which needs a keyword list (`function`, `class`, `const`, `case`, `enum`, visibility modifiers,
 * attributes, `readonly`, `static`, …) and would false-positive on the legitimate inline
 * `/** @var Foo $x *`+`/` annotation before a `foreach` — of which this tree has three. The two-in-a-row rule has
 * a zero false-positive rate and catches the shape that has actually recurred four rounds running. Widening it
 * is a real option, not an impossibility; it just needs the annotation case handled first.
 *
 * `--dump-rules` reports the SEPARATOR token set and the meta-suite generates one case per entry from it, so
 * deleting an entry deletes its own case. That wiring was CLAIMED here for one commit while nothing consumed
 * the output (round 19) — the suite's shape cases were hand-picked and its only rule-set assertion counted
 * `--dump-rules` lines, which a `printf` over an empty array satisfies. Both are now real.
 */

const REPO_ROOT = __DIR__ . '/../..';

/**
 * Tokens permitted BETWEEN two doc comments while still leaving the first orphaned.
 *
 * Each is here because PHP skips it when resolving which declaration a doc comment attaches to — verified by
 * reflection rather than reasoned, since that is the whole question this gate answers.
 *
 * **`T_ATTRIBUTE` is deliberately NOT in this list, having been in it for one commit** (round 19). An attribute
 * IS a separator, but it cannot be expressed as one token: `T_ATTRIBUTE` is only the opening `#[`, and the name,
 * arguments and closing `]` inside it are ordinary tokens that would end the run. It is therefore skipped whole
 * by the bracket-counting branch in the scan below, which runs BEFORE this list is consulted — so listing it here
 * changed nothing, and removing it changed nothing either. A rule that no failure path reads is the
 * declared-but-unconsulted shape CLAUDE.md § Gotchas records, and it is worse in a rule set than elsewhere
 * because `--dump-rules` reports it and the count looked like coverage.
 */
const ORPHAN_SEPARATORS = [
    'T_WHITESPACE',
    'T_COMMENT',
];

if (($argv[1] ?? '') === '--dump-rules') {
    foreach (ORPHAN_SEPARATORS as $separator) {
        printf("separator\t%s\n", $separator);
    }

    exit(0);
}

chdir(REPO_ROOT);

// `git ls-files`, NOT a recursive walk. A walk reads whatever is sitting in the working tree, and this repo
// sometimes contains full copies of ITSELF: a parallel review round runs agents in git worktrees and the harness
// places them at `.claude/worktrees/<agent>/`, inside the repo. Round 17 had to make this same correction in
// `test-gates.sh`, where a walk was reading four repositories and reporting findings belonging to none of them.
exec("git ls-files -z -- '*.php'", $output, $status);

if (0 !== $status) {
    fwrite(STDERR, "no-orphaned-docblocks: FAIL — `git ls-files` failed, so nothing was inspected.\n");

    exit(1);
}

$files = array_values(array_filter(explode("\0", implode('', $output)), static fn(string $p): bool => '' !== $p));

$separators = [];

foreach (ORPHAN_SEPARATORS as $name) {
    if (defined($name)) {
        $separators[] = constant($name);
    }
}

$inspected = 0;
$violations = [];
$malformed = [];

foreach ($files as $file) {
    $source = @file_get_contents($file);

    if (false === $source) {
        continue;
    }

    ++$inspected;
    $tokens = token_get_all($source);
    $pendingDocLine = null;

    // An ATTRIBUTE must be skipped WHOLE, not just at its opening token. `T_ATTRIBUTE` is only the `#[`; the
    // name, arguments and closing `]` inside it are ordinary tokens, so treating the opener alone as a separator
    // left `#[Deprecated]` between two doc comments clearing the pending one — the attribute case was still
    // missed on the first attempt at this fix, by exactly the "the test does not arrive" mechanism this
    // repository records. Attributes nest (an argument may be an array), so this counts brackets rather than
    // looking for the next `]`.
    $attributeDepth = 0;

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            if ($attributeDepth > 0) {
                if ('[' === $token) {
                    ++$attributeDepth;
                } elseif (']' === $token) {
                    --$attributeDepth;
                }

                continue;
            }

            // Any plain-character token outside an attribute — `{`, `;`, `(` — is a declaration boundary as far
            // as this rule cares, so it clears the pending doc comment.
            $pendingDocLine = null;

            continue;
        }

        [$id, , $line] = [$token[0], $token[1], $token[2]];

        if (\T_ATTRIBUTE === $id) {
            ++$attributeDepth;

            continue;
        }

        if ($attributeDepth > 0) {
            continue;
        }

        if (\T_DOC_COMMENT === $id) {
            if (null !== $pendingDocLine) {
                $violations[] = $file . ':' . $pendingDocLine;
            }

            // THE SECOND AXIS: a doc comment's INTERIOR, not its position.
            //
            // Round 3 of the PHPStan certification found two shapes this gate was blind to, one of them
            // introduced by the commit under review and one there since 2026-07-31 — and NOTHING else could see
            // either: `php -l` treats a doc comment as one token, `php-cs-fixer` reported `0 of 89`, and PHPStan
            // reported `[OK] No errors`. The rendered text is what a reader and every doc tool consume, so a
            // continuation line that is not a continuation line is a defect in the artefact this gate exists to
            // protect. Two spellings, both real:
            //
            //   - a line beginning `//` instead of ` * `, which renders the `//` as prose;
            //   - a second `/**` on its own line, which renders a literal `/**` inside its own block.
            //
            // Checked on the TOKEN's own text rather than by re-reading the file, so it cannot drift out of step
            // with what the tokenizer actually saw. The first and last lines are excluded: they carry the `/**`
            // and `*/` delimiters by construction.
            $docLines = preg_split('/\R/', $token[1]) ?: [];

            foreach (array_slice($docLines, 1, max(0, count($docLines) - 2)) as $offset => $docLine) {
                $trimmed = ltrim($docLine);

                if ('' === $trimmed || str_starts_with($trimmed, '*')) {
                    continue;
                }

                $malformed[] = [$file . ':' . ($line + $offset + 1), rtrim($docLine)];
            }

            $pendingDocLine = $line;

            continue;
        }

        if (!in_array($id, $separators, true)) {
            $pendingDocLine = null;
        }
    }
}

// Printed UNCONDITIONALLY and before the verdict, so the meta-suite can prove the gate looked at something. A
// gate that inspected zero files reports OK indistinguishably from one that swept the tree, and CLAUDE.md
// § Gotchas records a fixture omitting its input making every assertion about it vacuous while staying green.
printf(
    "counts — inspected=%d violations=%d malformed=%d separators=%d\n",
    $inspected,
    count($violations),
    count($malformed),
    count($separators),
);

if (0 === $inspected) {
    fwrite(STDERR, "no-orphaned-docblocks: FAIL — inspected NO files, so this gate proved nothing.\n");

    exit(1);
}

if (0 === count($separators)) {
    fwrite(STDERR, "no-orphaned-docblocks: FAIL — the separator set is EMPTY, so only immediately adjacent doc\n"
        . "  comments would be found and every shape round 18 filed would be missed again.\n");

    exit(1);
}

if ([] !== $violations) {
    fwrite(STDERR, "no-orphaned-docblocks: FAIL — a doc comment attaches to no declaration.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, sprintf(
            "  %s — the doc comment starting here is followed by another doc comment with no declaration\n"
            . "     between them, so PHP attaches only the LATER one. Move this block to the declaration it\n"
            . "     describes, or merge the two. Do NOT separate them with a blank line: that changes nothing\n"
            . "     about which declaration each attaches to, and this gate is a tokenizer pass precisely so\n"
            . "     that it is not defeated by whitespace.\n",
            $violation,
        ));
    }

    exit(1);
}

if ([] !== $malformed) {
    fwrite(STDERR, "no-orphaned-docblocks: FAIL — a doc comment has a MALFORMED continuation line.\n");

    foreach ($malformed as [$where, $text]) {
        fwrite(STDERR, sprintf(
            "  %s — this line is inside a /** */ block but does not begin with `*`, so its text renders\n"
            . "     verbatim: %s\n"
            . "     Two spellings produce this and both have shipped here: a `//` line comment pasted into a doc\n"
            . "     block, and a duplicated `/**` opener. No other tool in this tier sees it — `php -l` treats the\n"
            . "     whole block as one token, `php-cs-fixer` does not reflow doc bodies, and PHPStan only parses\n"
            . "     the tags.\n",
            $where,
            $text,
        ));
    }

    exit(1);
}

printf(
    "no-orphaned-docblocks: OK — %d file(s) carry no stranded doc comment and no malformed continuation.\n",
    $inspected,
);
