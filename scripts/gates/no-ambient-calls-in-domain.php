#!/usr/bin/env php
<?php

/*
 * Gate: the domain layer reads no clock, no randomness, no environment and no filesystem.
 *
 * Why it exists as a SEPARATE gate from layer-dependencies.php: those two rules look alike and are
 * detected in completely different ways. A framework dependency arrives as a `use` statement, so an
 * import check finds it. `time()`, `random_int()`, `getenv()` and `file_get_contents()` are **bare
 * function calls with no import at all** — an import check is blind to every one of them.
 *
 * What breaks without it: a domain that reads the clock cannot be tested at a date, so nobody writes
 * the test for the invoice that is due tomorrow. A domain that reads randomness cannot be tested
 * reproducibly. A domain that reads the environment behaves differently in production than in the suite
 * that proved it correct. Time and identity come in through a port — Domain\Shared\Clock and
 * Domain\Shared\IdGenerator — and the adapters live in Infrastructure/.
 *
 * `bcadd` and friends are deliberately NOT banned: they are pure, deterministic, and the reason the
 * domain needs no arbitrary-precision package.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

const REPO_ROOT = __DIR__ . '/../..';
const DOMAIN = REPO_ROOT . '/api/src/Domain';

/** Banned function => why, shown in the failure so the fix is obvious. */
const BANNED_FUNCTIONS = [
    // ---- the clock
    'time' => 'inject Domain\\Shared\\Clock',
    'microtime' => 'inject Domain\\Shared\\Clock',
    'hrtime' => 'inject Domain\\Shared\\Clock',
    'date' => 'inject Domain\\Shared\\Clock, then format in UI/',
    'mktime' => 'inject Domain\\Shared\\Clock',
    'strtotime' => 'parse at the boundary, in UI/ or Infrastructure/',
    'date_create' => 'inject Domain\\Shared\\Clock',
    'checkdate' => 'validate at the boundary',

    // ---- randomness
    'rand' => 'inject Domain\\Shared\\IdGenerator',
    'mt_rand' => 'inject Domain\\Shared\\IdGenerator',
    'random_int' => 'inject Domain\\Shared\\IdGenerator',
    'random_bytes' => 'inject Domain\\Shared\\IdGenerator',
    'uniqid' => 'inject Domain\\Shared\\IdGenerator',
    'shuffle' => 'a domain rule must not depend on ordering luck',
    'array_rand' => 'a domain rule must not depend on ordering luck',

    // ---- the environment
    'getenv' => 'configuration is injected, never read',
    'putenv' => 'the domain never mutates its environment',
    'ini_get' => 'configuration is injected, never read',
    'ini_set' => 'the domain never mutates its environment',
    'php_uname' => 'the domain does not know what it runs on',
    'gethostname' => 'the domain does not know what it runs on',

    // ---- I/O
    'file_get_contents' => 'load in Infrastructure/ and pass the data in',
    'file_put_contents' => 'persist through a repository port',
    'fopen' => 'persist through a repository port',
    'file' => 'load in Infrastructure/ and pass the data in',
    'unlink' => 'persist through a repository port',
    'is_file' => 'the domain does not touch the filesystem',
    'file_exists' => 'the domain does not touch the filesystem',
    'glob' => 'the domain does not touch the filesystem',
    'scandir' => 'the domain does not touch the filesystem',
    'curl_init' => 'call the outside world from Infrastructure/',
    'fsockopen' => 'call the outside world from Infrastructure/',
    'mail' => 'send from Infrastructure/',
    'header' => 'HTTP belongs to UI/',
    'setcookie' => 'HTTP belongs to UI/',
    'session_start' => 'HTTP belongs to UI/',
    'error_log' => 'inject a logger port if the domain must speak',

    // ---- global state and control flow
    'exit' => 'a domain rule throws, it does not end the process',
    'die' => 'a domain rule throws, it does not end the process',
    'sleep' => 'the domain does not wait',
    'usleep' => 'the domain does not wait',
    'extract' => 'no dynamic symbol creation',
    'eval' => 'no dynamic code',
    'compact' => 'use an explicit array or a value object',
];

/** Classes whose *instantiation* reads the clock, even though naming the type is fine. */
const BANNED_INSTANTIATIONS = [
    'DateTime' => 'inject Domain\\Shared\\Clock; naming the type is fine, constructing it is not',
    'DateTimeImmutable' => 'inject Domain\\Shared\\Clock; naming the type is fine, constructing it is not',
];

exit(main());

function main(): int
{
    if (!is_dir(DOMAIN)) {
        fwrite(\STDOUT, "no-ambient-calls: api/src/Domain does not exist yet, nothing to check.\n");

        return 0;
    }

    $violations = [];
    $filesChecked = 0;

    foreach (phpFilesIn(DOMAIN) as $file) {
        ++$filesChecked;
        $violations = [...$violations, ...inspect($file)];
    }

    if ([] !== $violations) {
        fwrite(\STDERR, "no-ambient-calls: FAIL — the domain layer reaches outside itself.\n\n");

        foreach ($violations as $violation) {
            fwrite(\STDERR, '  ' . $violation . "\n");
        }

        fwrite(\STDERR, "\nSee CLAUDE.md, \"Architecture\": the domain layer is pure.\n");

        return 1;
    }

    fwrite(\STDOUT, \sprintf(
        "no-ambient-calls: OK — %d domain file(s) read no clock, randomness, environment or filesystem.\n",
        $filesChecked,
    ));

    return 0;
}

/** @return list<string> */
function inspect(string $file): array
{
    $source = file_get_contents($file);

    if (false === $source) {
        fwrite(\STDERR, "Could not read {$file}\n");
        exit(1);
    }

    $tokens = \PhpToken::tokenize($source);
    $count = \count($tokens);
    $violations = [];

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];

        // exit and die are language constructs, not T_STRING function names.
        if ($token->is([\T_EXIT])) {
            $violations[] = describe($file, $token->line, strtolower($token->text), BANNED_FUNCTIONS['exit']);

            continue;
        }

        if (!$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = strtolower(ltrim($token->text, '\\'));
        $previous = previousMeaningfulToken($tokens, $index);

        // `new DateTimeImmutable(...)` — the type in a signature or a docblock is untouched.
        if (null !== $previous && $previous->is(\T_NEW)) {
            foreach (BANNED_INSTANTIATIONS as $class => $reason) {
                if ($name === strtolower($class)) {
                    $violations[] = describe($file, $token->line, 'new ' . $class, $reason);
                }
            }

            continue;
        }

        if (!isset(BANNED_FUNCTIONS[$name])) {
            continue;
        }

        // A method call or a property is not the global function: $clock->time(), self::date(),
        // "function time()" declaring our own, or a constant named TIME.
        if (null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_CONST])) {
            continue;
        }

        // Only an actual call counts: the next meaningful token must be an opening parenthesis.
        $next = nextMeaningfulToken($tokens, $index);

        if (null === $next || '(' !== $next->text) {
            continue;
        }

        $violations[] = describe($file, $token->line, $name . '()', BANNED_FUNCTIONS[$name]);
    }

    return $violations;
}

function describe(string $file, int $line, string $what, string $reason): string
{
    return \sprintf('%s:%d — %s is ambient. %s.', relative($file), $line, $what, $reason);
}

/** @param list<\PhpToken> $tokens */
function previousMeaningfulToken(array $tokens, int $index): ?\PhpToken
{
    for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
        if (!$tokens[$cursor]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
            return $tokens[$cursor];
        }
    }

    return null;
}

/** @param list<\PhpToken> $tokens */
function nextMeaningfulToken(array $tokens, int $index): ?\PhpToken
{
    $count = \count($tokens);

    for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
        if (!$tokens[$cursor]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
            return $tokens[$cursor];
        }
    }

    return null;
}

/** @return list<string> */
function phpFilesIn(string $directory): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

    /** @var \SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isFile() && 'php' === $entry->getExtension()) {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

function relative(string $path): string
{
    $root = realpath(REPO_ROOT);

    return false === $root ? $path : str_replace($root . '/', '', $path);
}
