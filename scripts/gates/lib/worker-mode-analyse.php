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
 * THE ANALYSIS CORE OF `worker-mode-blocked.sh`, in PHP because the rules need a real scan and bash string
 * expansion is the wrong tool for one.
 *
 * WHY THIS EXISTS RATHER THAN MORE BASH. Version 4 of that gate inverted its three enumerations -- scope, knobs,
 * values -- and a certification round then defeated it three more times, all in the NORMALISATION the inversion
 * introduced:
 *
 *   - a continuation right after `="` drove a seam value to EMPTY (`="\` -> strip `\` -> `="` -> strip `[=:]` ->
 *     `"` -> strip quote -> ``), so a non-empty seam read as empty;
 *   - a continuation INSIDE the variable name (`ENV APP_RUN\` + `TIME=...`) meant no keyword appeared on any
 *     physical line, defeating both value rules AND the per-file fast reject at once;
 *   - a value beginning `#` collapsed to empty through the inline-comment strip -- a REGRESSION, because version 3
 *     tested the raw line and caught it.
 *
 * The lesson: a transformation set is an enumeration too. Each strip was added to handle a real spelling, and the
 * pile of them became the attack surface. So this file does exactly TWO total operations, neither of which is a
 * list of cases:
 *
 *   1. JOIN line continuations into LOGICAL lines. A declaration is a logical line, not a physical one, and every
 *      continuation attack follows from analysing physical lines.
 *   2. SCAN each logical line once, tracking quote state, yielding (a) the code portion before an UNQUOTED comment
 *      start and (b) whether quotes balance.
 *
 * Then the rules read those two outputs and never transform anything again. A keyword occurrence must be followed
 * by one of a few committed literals -- so no value is ever extracted, and there is nothing left to collapse.
 */

final class WorkerModeAnalysis
{
    /**
     * Comment leaders, by first non-space characters of a logical line. `#` covers dotenv, Dockerfile, YAML, Caddy
     * and Make; the rest cover the PHP and HTML files the inverted scope brought in -- `api/public/index.php`
     * documents the forbidden value verbatim inside a docblock.
     */
    private const COMMENT_LEADERS = ['#', '*', '//', '/*', '<!--'];

    /**
     * @param list<string> $permittedRuntimeLines
     * @param list<string> $permittedSeamLines
     * @param list<string> $seams
     */
    public function __construct(
        private readonly string $permittedRuntime,
        private readonly array $permittedRuntimeLines,
        private readonly array $permittedSeamLines,
        private readonly array $seams,
        private readonly string $structuralPlaceholder,
    ) {}

    /**
     * How many APPROVED runtime and seam declarations were seen. The gate refuses a run where this is zero: a
     * renamed variable, a moved file or a broken derivation would otherwise be indistinguishable from a clean sweep.
     */
    private int $approvedDeclarations = 0;

    public function approvedDeclarations(): int
    {
        return $this->approvedDeclarations;
    }

    /**
     * Join `\`-continued physical lines into logical ones.
     *
     * A comment-only physical line INSIDE a continuation is dropped rather than concatenated, because that is what
     * BuildKit does -- `infra/api/Dockerfile`'s `ENV` block interleaves comments between its continuation lines,
     * and concatenating them would produce a logical line no literal could ever match.
     *
     * @return list<array{line: string, at: int}> the logical line and the 1-based physical line it starts on
     */
    public function logicalLines(string $contents): array
    {
        $out = [];
        $buffer = null;
        $startedAt = 0;

        foreach (explode("\n", str_replace("\r\n", "\n", $contents)) as $index => $physical) {
            $physical = rtrim($physical, "\r");
            $number = $index + 1;
            $continues = str_ends_with($physical, '\\');
            $body = $continues ? substr($physical, 0, -1) : $physical;

            if (null === $buffer) {
                // A COMMENT LINE NEVER STARTS A CONTINUATION. It did, and it was the worst defect in this file: a
                // comment ending in `\` began a buffer, the joined logical line then started with `#`, and
                // `violationsFor()` discarded the whole thing as a comment -- swallowing every real declaration up
                // to the next uncontinued line. `# a note \` + `APP_RUNTIME=...FrankenPhpWorkerRuntime` was
                // invisible, and `docker compose config` rendered that worker runtime at five sites. BOTH earlier
                // versions of this gate refused it, so it was a REGRESSION, and the same shape hid an active
                // `worker { }` block in the Caddyfile from `caddyViolations()`.
                if ($this->startsWithCommentLeader(ltrim($body))) {
                    $out[] = ['line' => $physical, 'at' => $number];
                    continue;
                }
                $startedAt = $number;
                $buffer = $body;
            } else {
                // NO SEPARATOR IS INSERTED and the body is NOT left-trimmed, because Docker removes only the
                // backslash and the newline. Joining with a space repaired `ENV APP_RUN\` + `TIME=...` into
                // `ENV APP_RUN TIME=...`, in which the keyword does not appear -- so the very attack this join
                // exists to defeat still walked through, and the meta-suite said so.
                if (!$this->startsWithCommentLeader(ltrim($body))) {
                    $buffer .= $body;
                } else {
                    // A COMMENT INSIDE A CONTINUATION DOES NOT END IT. BuildKit skips the comment line and keeps
                    // reading the instruction, so terminating here let the split-keyword attack back in simply by
                    // putting a comment at the split point -- proven with a real BuildKit build of the tracked
                    // bytes. The docblock above claimed this handling "is what BuildKit does"; it was half of it.
                    continue;
                }
            }

            if (!$continues) {
                $out[] = ['line' => $buffer, 'at' => $startedAt];
                $buffer = null;
            }
        }

        if (null !== $buffer) {
            $out[] = ['line' => $buffer, 'at' => $startedAt];
        }

        return $out;
    }

    /**
     * ONE scan, tracking quote state. Returns the code portion (everything before an UNQUOTED comment start) and
     * whether quotes balance.
     *
     * The balance flag is a rule in its own right, not a diagnostic: an unbalanced quote means the value continues
     * onto lines this analysis cannot attribute to it, which is exactly how a multi-line `SERVER_NAME` closes the
     * Caddyfile's site block and opens its own containing `php_server { worker ... }`. Refusing the unbalanced line
     * closes that without the gate having to understand Caddy's grammar.
     *
     * @return array{code: string, balanced: bool}
     */
    public function scan(string $logicalLine): array
    {
        $quote = null;
        $length = strlen($logicalLine);
        $code = $logicalLine;

        for ($i = 0; $i < $length; ++$i) {
            $char = $logicalLine[$i];

            if (null !== $quote) {
                if ('\\' === $char) {
                    ++$i;                       // an escaped character cannot close a quote
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
                continue;
            }

            if ('#' === $char) {
                $code = substr($logicalLine, 0, $i);
                break;
            }

            // NO `//` CUT. It was here and it was pure loss: `//` is a comment leader in none of the five dialects
            // this file lists (dotenv, Dockerfile, YAML, Caddy, Make), so any URL earlier on a line hid everything
            // after it -- `DOCS_URL=https://…` masked an `APP_RUNTIME` and a seam on the same logical line, which
            // both earlier versions refused. Removing it changed no meta-suite case, which is the proof it never
            // protected anything: no in-scope file mentions a keyword after `//`. The `*`-leader rule above still
            // covers PHP docblocks, which is the only reason `//` looked plausible.
        }

        return ['code' => rtrim($code), 'balanced' => null === $quote];
    }

    /**
     * Apply every rule to one file's contents.
     *
     * @return list<string> violations, each a complete sentence naming the physical line
     */
    public function violationsFor(string $relative, string $contents): array
    {
        $violations = [];

        foreach ($this->logicalLines($contents) as $logical) {
            $raw = $logical['line'];
            $at = $logical['at'];
            $trimmed = ltrim($raw);

            if ('' === $trimmed || $this->startsWithCommentLeader($trimmed)) {
                continue;                       // a whole-line comment is not configuration
            }

            $scan = $this->scan($raw);

            // UNBALANCED QUOTES FIRST, and before any keyword test, because the reason to refuse the line is that
            // its value is not on it. Only checked when the line mentions something we care about, so an unrelated
            // apostrophe in prose is not a finding.
            if (!$scan['balanced'] && $this->mentionsAnything($raw)) {
                $violations[] = sprintf(
                    '%s:%d: a configuration keyword appears on a line with UNBALANCED QUOTES, so its value '
                    . 'continues onto lines that cannot be attributed to it. A multi-line value spliced into the '
                    . 'Caddyfile can close one block and open another: %s',
                    $relative,
                    $at,
                    $this->excerpt($trimmed),
                );
                continue;
            }

            $code = $scan['code'];

            foreach ($this->keywords() as $keyword) {
                $offset = 0;
                while (false !== $position = strpos($code, $keyword, $offset)) {
                    $offset = $position + 1;
                    $remainder = substr($code, $position);

                    // ONLY A DECLARATION POSITION IS JUDGED, and this is uniform across every keyword rather than
                    // a special case for one. A name is being DECLARED when it starts the line or follows
                    // whitespace or a quote; anywhere else it is being CONSUMED or merely mentioned, and a
                    // consumption cannot set a value:
                    //
                    //   `{$CADDY_GLOBAL_OPTIONS}`   the Caddyfile SPLICING the seam in -- five of these
                    //   `${SERVER_NAME:-:80}`       compose READING it with a default
                    //   `${SERVER_NAME:?message}`   ...and the message text, which names it a third time
                    //
                    // The first version judged every occurrence and produced six false positives on the real tree
                    // immediately, every one of them a consumption site. The dangerous form is still caught,
                    // because a line that genuinely sets the variable has the name at a declaration position:
                    // `APP_RUNTIME: '${APP_RUNTIME:-<anything else>}'` is judged at offset 0 and matches no
                    // literal. `SOMEVAR: '${APP_RUNTIME:-x}'` is skipped and SHOULD be -- it sets SOMEVAR.
                    if (0 !== $position && 1 !== preg_match('/^[\s"\']$/', $code[$position - 1])) {
                        continue;
                    }

                    if (null !== $violation = $this->judge($relative, $at, $keyword, $remainder, $trimmed)) {
                        $violations[] = $violation;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The whole rule, and it extracts NO value: the text from the keyword onwards must BEGIN with one of a few
     * committed literals, and what follows the literal must be whitespace or end-of-line. That is what closes
     * `ENV KEY value` (no `=` at all), `KEY+=`, a quoted `"KEY":` and both continuation splits with one test --
     * none of which this method knows about.
     */
    private function judge(string $relative, int $at, string $keyword, string $remainder, string $context): ?string
    {
        $permitted = $keyword === $this->structuralPlaceholder
            ? null                              // handled by its own rule below
            : ($this->isRuntimeKeyword($keyword) ? $this->permittedRuntimeLines : $this->permittedSeamLines);

        if (null === $permitted) {
            return $this->judgeStructuralPlaceholder($relative, $at, $keyword, $remainder, $context);
        }

        foreach ($permitted as $literal) {
            if (!str_starts_with($remainder, $literal)) {
                continue;
            }
            $after = substr($remainder, strlen($literal));
            // WHITESPACE OR END-OF-LINE ONLY. A quote was accepted here for one iteration and it was a hole: the
            // literal `FRANKENPHP_CONFIG=` then prefix-matched `FRANKENPHP_CONFIG="worker /app/public/index.php"`,
            // because the opening quote of the value was read as the terminator. Two meta-suite cases caught it.
            // The interpolated form needs no allowance -- its literal already ENDS with the closing quote.
            if ('' === $after || preg_match('/^\s/', $after)) {
                ++$this->approvedDeclarations;
                return null;                    // an approved declaration, and nothing follows it on this line
            }
        }

        return sprintf(
            '%s:%d: `%s` is not one of the permitted %s declarations. %s',
            $relative,
            $at,
            $this->excerpt($remainder),
            $this->isRuntimeKeyword($keyword) ? 'APP_RUNTIME' : 'seam',
            $this->isRuntimeKeyword($keyword)
                ? sprintf('The only runtime is "%s".', $this->permittedRuntime)
                : 'Every Caddyfile seam must be declared EMPTY while worker mode is blocked -- it splices verbatim '
                    . 'into the Caddyfile, and a value on a line the gate cannot read is a value nobody checked.',
        );
    }

    /**
     * `SERVER_NAME` cannot be pinned to a literal -- it is the public hostname and an operator must set it. But it
     * splices at SITE-BLOCK position, immediately before the `{` that opens the block, so any value carrying a
     * brace or a newline can restructure the Caddyfile. A certification round proved exactly that, with FrankenPHP's
     * own `adapt` reporting the injected workers.
     *
     * So the rule is STRUCTURAL rather than an allow-list: after removing a `${NAME:-default}` / `${NAME:?message}`
     * interpolation wrapper -- which is where our own compose files legitimately carry braces -- no brace may
     * remain. That leaves every hostname free and refuses everything that could change the grammar. Exempting the
     * variable, which is what version 4 did, is what made the injection reachable.
     */
    private function judgeStructuralPlaceholder(
        string $relative,
        int $at,
        string $keyword,
        string $remainder,
        string $context,
    ): ?string {
        $value = preg_replace('/^' . preg_quote($keyword, '/') . '\s*[:=]?\s*/', '', $remainder) ?? $remainder;
        $value = trim($value, "\"' \t");
        // Our own interpolation forms: `${SERVER_NAME:-:80}` and `${SERVER_NAME:?message}`.
        $withoutInterpolation = preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*(?::[-?][^}]*)?\}/', '', $value) ?? $value;

        if (str_contains($withoutInterpolation, '{') || str_contains($withoutInterpolation, '}')) {
            return sprintf(
                '%s:%d: %s carries a BRACE outside a `${...}` interpolation. It splices at site-block position in '
                . 'the Caddyfile, so a brace there can close one block and open another containing '
                . '`php_server { worker ... }`: %s',
                $relative,
                $at,
                $keyword,
                $this->excerpt($context),
            );
        }

        return null;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return [...$this->seams, $this->structuralPlaceholder, 'APP_RUNTIME'];
    }

    private function isRuntimeKeyword(string $keyword): bool
    {
        return 'APP_RUNTIME' === $keyword;
    }

    private function mentionsAnything(string $line): bool
    {
        foreach ($this->keywords() as $keyword) {
            if (str_contains($line, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function startsWithCommentLeader(string $trimmed): bool
    {
        foreach (self::COMMENT_LEADERS as $leader) {
            if (str_starts_with($trimmed, $leader)) {
                return true;
            }
        }

        return false;
    }

    private function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;
    }
}
