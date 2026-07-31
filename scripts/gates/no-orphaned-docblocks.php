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
 * missed THREE of five genuine shapes — every one confirmed orphaned by `ReflectionMethod::getDocComment()`:
 *
 *   1. a BLANK LINE between the two blocks — the most natural way anyone would author them;
 *   2. an `#[Attribute]` between them;
 *   3. a single-line second block (`/** Attached. *`+`/`);
 *   4. `*`+`/ /**` on ONE line — which the old comment asserted "is not this defect". That was simply FALSE, and
 *      a false impossibility used to justify a blind spot is the shape CLAUDE.md § Gotchas records five times;
 *   5. an orphan before a `const` or an `enum` case rather than a method.
 *
 * The lesson is the general one: a positional rule enforces one SPELLING of a rule, and the defect is not
 * positional at all — it is *"this doc comment attaches to no declaration"*, which is a question about tokens.
 * `token_get_all()` answers it directly: two `T_DOC_COMMENT`s with nothing but whitespace, ordinary comments or
 * an attribute between them. All five shapes collapse into that one rule, and so does any sixth nobody has
 * thought of yet.
 *
 * Registered `--dump-rules` output is the SEPARATOR token set, so the meta-suite can generate a case per
 * separator and deleting one deletes its own case.
 */

const REPO_ROOT = __DIR__ . '/../..';

/**
 * Tokens permitted BETWEEN two doc comments while still leaving the first orphaned.
 *
 * Each one is here because PHP skips it when resolving which declaration a doc comment attaches to — verified by
 * reflection rather than reasoned, since that is the whole question this gate answers. `T_ATTRIBUTE` is the
 * non-obvious member: an attribute between two doc comments does not rescue the first.
 */
const ORPHAN_SEPARATORS = [
    'T_WHITESPACE',
    'T_COMMENT',
    'T_ATTRIBUTE',
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
printf("counts — inspected=%d violations=%d separators=%d\n", $inspected, count($violations), count($separators));

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

printf("no-orphaned-docblocks: OK — %d file(s) carry no stranded doc comment.\n", $inspected);
