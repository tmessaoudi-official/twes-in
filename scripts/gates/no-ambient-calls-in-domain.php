#!/usr/bin/env php
<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
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
    'gmdate' => 'inject Domain\\Shared\\Clock, then format in UI/',
    'getdate' => 'inject Domain\\Shared\\Clock',
    'localtime' => 'inject Domain\\Shared\\Clock',
    'gmmktime' => 'inject Domain\\Shared\\Clock',
    'idate' => 'inject Domain\\Shared\\Clock',
    'strftime' => 'inject Domain\\Shared\\Clock, then format in UI/',
    'date_default_timezone_get' => 'the domain works in UTC and does not ask',
    'date_default_timezone_set' => 'the domain never mutates global state',
    'date_create_immutable' => 'inject Domain\\Shared\\Clock',
    'date_create_from_format' => 'parse at the boundary',
    'gettimeofday' => 'inject Domain\\Shared\\Clock',

    // ---- randomness
    'rand' => 'inject Domain\\Shared\\IdGenerator',
    'mt_rand' => 'inject Domain\\Shared\\IdGenerator',
    'random_int' => 'inject Domain\\Shared\\IdGenerator',
    'random_bytes' => 'inject Domain\\Shared\\IdGenerator',
    'uniqid' => 'inject Domain\\Shared\\IdGenerator',
    'shuffle' => 'a domain rule must not depend on ordering luck',
    'array_rand' => 'a domain rule must not depend on ordering luck',
    'lcg_value' => 'inject Domain\\Shared\\IdGenerator',
    'mt_srand' => 'the domain never seeds a generator',
    'srand' => 'the domain never seeds a generator',
    'bin2hex' => 'only reached here via random_bytes; generate identifiers in Infrastructure/',
    'str_shuffle' => 'inject Domain\\Shared\\IdGenerator',
    'openssl_random_pseudo_bytes' => 'inject Domain\\Shared\\IdGenerator',
    'random' => 'inject Domain\\Shared\\IdGenerator',

    // ---- the environment
    'getenv' => 'configuration is injected, never read',
    'putenv' => 'the domain never mutates its environment',
    'ini_get' => 'configuration is injected, never read',
    'ini_set' => 'the domain never mutates its environment',
    'php_uname' => 'the domain does not know what it runs on',
    'gethostname' => 'the domain does not know what it runs on',
    'get_cfg_var' => 'configuration is injected, never read',
    'filter_input' => 'reads a superglobal; the request belongs to UI/',
    'filter_input_array' => 'reads a superglobal; the request belongs to UI/',
    'getopt' => 'CLI arguments belong to UI/',
    'getmypid' => 'the domain does not know what process it is',
    'php_sapi_name' => 'the domain does not know how it was invoked',
    'sys_getloadavg' => 'the domain does not inspect the machine',
    'memory_get_usage' => 'the domain does not inspect the machine',
    'sys_get_temp_dir' => 'the domain does not touch the filesystem',
    'getcwd' => 'the domain does not touch the filesystem',
    'chdir' => 'the domain never mutates process state',

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
    'readfile' => 'load in Infrastructure/ and pass the data in',
    'opendir' => 'the domain does not touch the filesystem',
    'readdir' => 'the domain does not touch the filesystem',
    'touch' => 'persist through a repository port',
    'mkdir' => 'persist through a repository port',
    'rmdir' => 'persist through a repository port',
    'rename' => 'persist through a repository port',
    'copy' => 'persist through a repository port',
    'stream_socket_client' => 'call the outside world from Infrastructure/',
    'stream_get_contents' => 'read streams in Infrastructure/',
    'file_put_contents_atomic' => 'persist through a repository port',

    // ---- command execution. Banned outright: the gate previously stopped mail() and curl_init() while
    // leaving shell_exec() and proc_open() untouched, which is the larger hole by some distance.
    'shell_exec' => 'a domain rule never runs a subprocess',
    'exec' => 'a domain rule never runs a subprocess',
    'system' => 'a domain rule never runs a subprocess',
    'passthru' => 'a domain rule never runs a subprocess',
    'proc_open' => 'a domain rule never runs a subprocess',
    'popen' => 'a domain rule never runs a subprocess',
    'pcntl_fork' => 'a domain rule never forks',
    'posix_getpwuid' => 'the domain does not inspect the host',
    'escapeshellcmd' => 'only needed by code that runs subprocesses, which the domain does not',
    'escapeshellarg' => 'only needed by code that runs subprocesses, which the domain does not',
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

    // Indirect invocation. These are how every ban above is evaded in one step, so they are banned
    // outright in Domain/ rather than analysed: a domain rule has no legitimate need to call a function
    // chosen at runtime, and allowing it would make this gate advisory.
    'call_user_func' => 'call the function directly so this gate can see it',
    'call_user_func_array' => 'call the function directly so this gate can see it',
    'func_get_args' => 'declare the parameters explicitly',
];

/**
 * Superglobals. NOT function calls and NOT imports, so neither this gate's original token check nor
 * layer-dependencies.php could see them — `$_SERVER['REQUEST_TIME']` is an ambient clock read and
 * `$_ENV` is ambient configuration, and both passed every gate.
 */
const BANNED_VARIABLES = [
    '$_ENV' => 'configuration is injected, never read',
    '$_SERVER' => 'the request belongs to UI/; REQUEST_TIME is also an ambient clock read',
    '$_GET' => 'the request belongs to UI/',
    '$_POST' => 'the request belongs to UI/',
    '$_REQUEST' => 'the request belongs to UI/',
    '$_COOKIE' => 'the request belongs to UI/',
    '$_FILES' => 'the request belongs to UI/',
    '$_SESSION' => 'the request belongs to UI/',
    '$GLOBALS' => 'the domain has no global state',
];

/** Classes whose *instantiation* reads the clock, even though naming the type is fine. */
const BANNED_INSTANTIATIONS = [
    'DateTime' => 'inject Domain\\Shared\\Clock; naming the type is fine, constructing it is not',
    'DateTimeImmutable' => 'inject Domain\\Shared\\Clock; naming the type is fine, constructing it is not',
];

/*
 * `--dump-rules` prints this gate's rule data as JSON so scripts/gates/test-gates.sh can generate one
 * execution case per entry. Without it the meta-suite hand-picked a handful of names and pinned those,
 * which meant 60 of 71 entries could be deleted with the suite still reporting 37/37.
 */
if (isset($argv[1]) && '--dump-rules' === $argv[1]) {
    echo json_encode([
        'functions' => array_keys(BANNED_FUNCTIONS),
        'variables' => array_keys(BANNED_VARIABLES),
        'instantiations' => array_keys(BANNED_INSTANTIATIONS),
    ], \JSON_THROW_ON_ERROR), "\n";

    exit(0);
}

exit(main());

function main(): int
{
    // A gate that inspected nothing must not report OK. This one printed "does not exist yet, nothing to
    // check" — written when api/src/Domain genuinely did not exist — and kept printing it after the domain
    // landed, so renaming or relocating the directory leaves the two P0s this gate enforces silently
    // unchecked while composer gate:architecture stays green. Its twin, layer-dependencies.php, was fixed
    // for exactly this and this gate was not: same defect, same input, one gate apart.
    if (!is_dir(DOMAIN)) {
        fwrite(\STDERR, sprintf(
            "no-ambient-calls: FAIL — %s does not exist, so nothing was checked. If the domain layer moved, "
            . "update DOMAIN in this gate; if it has genuinely not been created yet, this gate is what "
            . "should tell you so rather than passing.\n",
            relative(DOMAIN),
        ));

        return 1;
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

    // The second empty state: the directory exists but holds no PHP. Same reasoning as above.
    if (0 === $filesChecked) {
        fwrite(\STDERR, sprintf(
            "no-ambient-calls: FAIL — inspected 0 files. %s exists but contains no PHP.\n",
            relative(DOMAIN),
        ));

        return 1;
    }

    fwrite(\STDOUT, sprintf(
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
    $count = count($tokens);
    $violations = [];

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];

        if ($token->is(\T_VARIABLE) && isset(BANNED_VARIABLES[$token->text])) {
            $violations[] = describe($file, $token->line, $token->text, BANNED_VARIABLES[$token->text]);

            continue;
        }

        // `new $className()` — a dynamic instantiation defeats the BANNED_INSTANTIATIONS check below,
        // and there is no way to know statically what it builds. The domain constructs its own types by
        // name, so this is banned rather than resolved.
        if ($token->is(\T_VARIABLE)) {
            $previous = previousMeaningfulToken($tokens, $index);

            if (null !== $previous && $previous->is(\T_NEW)) {
                $violations[] = describe(
                    $file,
                    $token->line,
                    'new ' . $token->text,
                    'a dynamically-named class cannot be checked; name the class directly',
                );
            }

            // `$c::createFromFormat()` — a dynamically-named STATIC CALL, banned for exactly the reason the
            // `new $var` ban above gives. ROUND 9 found the asymmetry: this file banned the dynamic
            // INSTANTIATION by shape and then checked static calls by name only, so `$c::` walked through.
            //
            // It lives INSIDE this branch on purpose. Written as a separate `if ($token->is(T_VARIABLE))`
            // block below, it was UNREACHABLE — this branch `continue`s for every variable token — and the
            // gate reported OK on the very attack it was added to catch. Third instance today of a rule that
            // could not fire; caught only because the attack was re-run rather than the fix assumed.
            $next = nextMeaningfulToken($tokens, $index);

            if (null !== $next && $next->is(\T_DOUBLE_COLON)) {
                $violations[] = describe(
                    $file,
                    $token->line,
                    $token->text . '::',
                    'a static call on a dynamically-named class cannot be checked; name the class directly',
                );
            }

            continue;
        }

        // `new ($expr)()` and `new (self::CONST)()` — PHP 8's parenthesised class expression, and a THIRD
        // syntax the check above does not see: its next token is `(`, not a T_VARIABLE. Round 2 named both
        // as still-open evasions of a gate that enforces a P0, and they were still open at round 9
        // [Verified: each reported `no-ambient-calls: OK` before this branch existed]. Banned by SHAPE
        // rather than by resolving the expression, because the whole point is that it cannot be resolved.
        if ($token->is(\T_NEW)) {
            $afterNew = nextMeaningfulToken($tokens, $index);

            if (null !== $afterNew && '(' === $afterNew->text) {
                $violations[] = describe(
                    $file,
                    $token->line,
                    'new (...)',
                    'a parenthesised class expression cannot be checked; name the class directly',
                );
            }

            continue;
        }

        // `use function time as now;` then `now()`. The import renames a banned function, and neither gate
        // saw it: the imported name `time` is followed by `as` or `;` rather than `(`, so the "only an
        // actual call counts" rule below skipped it, and the *alias* is an ordinary T_STRING that is in no
        // denylist. Checked at the import, which is the one place the real name is still written down.
        if ($token->is(\T_USE)) {
            $afterUse = nextMeaningfulToken($tokens, $index);

            // `use DateTimeImmutable as Stamp;` then `new Stamp()` or `Stamp::createFromFormat()`.
            // ROUND 9 (P1): this defeated BOTH the `new` rule and the static-call rule, because both key on
            // the class name AS WRITTEN AT THE CALL SITE — and three architecture gates reported OK on a
            // domain file that read the clock twice. It is not even an evasion: `use X as Y` is ordinary
            // style. Exactly the hole the `use function time as now` branch below was written to close, for
            // functions, and never written for CLASS imports. Checked at the import, which is the one place
            // the real name is still written down — the same remedy, applied to the other half.
            if (null !== $afterUse && !$afterUse->is([\T_FUNCTION, \T_CONST])) {
                for ($cursor = $index + 1; $cursor < $count && ';' !== $tokens[$cursor]->text; ++$cursor) {
                    if (!$tokens[$cursor]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                        continue;
                    }

                    $parts = explode('\\', $tokens[$cursor]->text);
                    $imported = strtolower(end($parts));

                    foreach (BANNED_INSTANTIATIONS as $class => $reason) {
                        if ($imported !== strtolower($class)) {
                            continue;
                        }

                        $violations[] = describe(
                            $file,
                            $tokens[$cursor]->line,
                            'use ' . $class,
                            $reason . '; importing it under another name does not change what it is, and an '
                            . 'alias is invisible to every check that reads the call site',
                        );
                    }
                }
            }

            if (null !== $afterUse && $afterUse->is(\T_FUNCTION)) {
                // Everything up to the statement's `;`, so grouped imports — `use function A\{time, date};`
                // — are covered by the same walk rather than needing their own branch.
                for ($cursor = $index + 1; $cursor < $count && ';' !== $tokens[$cursor]->text; ++$cursor) {
                    if (!$tokens[$cursor]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                        continue;
                    }

                    // The basename: `use function Foo\Bar\time` imports Foo\Bar\time, which is not PHP's
                    // time() — but the domain has no `Foo\Bar` to import from, so a namespaced import is
                    // already a third-party reference and layer-dependencies.php is the gate for that.
                    $parts = explode('\\', strtolower($tokens[$cursor]->text));
                    $imported = end($parts);

                    if (isset(BANNED_FUNCTIONS[$imported])) {
                        $violations[] = describe(
                            $file,
                            $tokens[$cursor]->line,
                            'use function ' . $imported,
                            BANNED_FUNCTIONS[$imported] . '; importing it under another name does not '
                            . 'change what it does',
                        );
                    }
                }
            }

            continue;
        }

        // exit and die are language constructs, not T_STRING function names.
        if ($token->is([\T_EXIT])) {
            $violations[] = describe($file, $token->line, strtolower($token->text), BANNED_FUNCTIONS['exit']);

            continue;
        }

        // eval is the same class of miss, and the worst of them: it tokenizes as T_EVAL, so the denylist
        // never saw it, while `--dump-rules` advertised it as enforced. One eval evades every ban in the
        // table at once — `eval('return time() . getenv("HOME") . file_get_contents("/etc/passwd");')` was
        // passing this gate. It gets its own branch for the same reason T_EXIT does.
        if ($token->is([\T_EVAL])) {
            $violations[] = describe($file, $token->line, 'eval', BANNED_FUNCTIONS['eval']);

            continue;
        }

        // Same class, and previously missed for the same reason: include/require are unbounded filesystem
        // reads AND code execution, and the backtick operator is shell execution — none is a T_STRING.
        if ($token->is([\T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE])) {
            $violations[] = describe(
                $file,
                $token->line,
                strtolower(trim($token->text)),
                'the domain does not read the filesystem or execute code it loaded',
            );

            continue;
        }

        if ('`' === $token->text) {
            $violations[] = describe(
                $file,
                $token->line,
                'the backtick operator',
                'shell execution; a domain rule never runs a subprocess',
            );

            continue;
        }

        // A banned name inside a quoted string is a callable in every practical case:
        // $f = 'time'; $f();  array_map('time', …);  \Closure::fromCallable('time').
        // Flagged rather than resolved — the domain has no legitimate reason to name one of these.
        if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
            // ltrim the backslash as well as the quotes. `'\time'` is a valid callable — PHP resolves it by
            // stripping the leading backslash — and writing it that way is natural style, not only an
            // evasion: `array_map('\getenv', …)` reads as deliberate global-namespace qualification. The
            // qualified-name branch below already did this; the string branch did not, eighteen lines apart.
            $literal = strtolower(ltrim(trim($token->text, "'\""), '\\'));

            if (isset(BANNED_FUNCTIONS[$literal])) {
                $violations[] = describe(
                    $file,
                    $token->line,
                    "'" . $literal . "' as a string callable",
                    BANNED_FUNCTIONS[$literal],
                );
            }

            continue;
        }

        if (!$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = strtolower(ltrim($token->text, '\\'));
        $previous = previousMeaningfulToken($tokens, $index);

        // A STATIC CALL on a banned class obtains one just as surely as `new` does, and
        // `DateTimeImmutable::createFromFormat()` is the dangerous case: undefined format fields default to
        // *now*, so it is a genuine clock read wearing the clothes of ordinary parsing. Round 2 named it and
        // it was still open at round 9. The rule is deliberately the CLASS, not a list of method names —
        // `createFromFormat`, `createFromInterface` and `createFromMutable` are all ways to hold one, and
        // enumerating methods would leave the next one open.
        //
        // `DateTimeImmutable::class` is EXCLUDED: naming the type is explicitly fine here (see
        // BANNED_INSTANTIATIONS' own reason strings), and a mapping file or a signature legitimately does it.
        // Compared case-insensitively against the keys, exactly as the `new` loop below does. Written
        // case-sensitively first, and it silently matched nothing: `\DateTimeImmutable` arrives as a
        // T_NAME_FULLY_QUALIFIED whose $name is already lowercased and leading-slash-stripped, so neither
        // the raw text nor ucfirst() equals the key. The fix caught the bug; the shape is the lesson — a
        // lookup that cannot match is indistinguishable from a rule that permits everything.
        foreach (BANNED_INSTANTIATIONS as $class => $reason) {
            if ($name !== strtolower($class)) {
                continue;
            }

            $next = nextMeaningfulToken($tokens, $index);

            if (null === $next || !$next->is(\T_DOUBLE_COLON)) {
                break;
            }

            // FROM THE `::` TOKEN'S OWN INDEX, not $index + 1. ROUND 9 (false positive): `nextMeaningfulToken`
            // skips whitespace and comments, so `::` can sit several tokens after $index — and scanning from
            // $index + 1 then returned the `::` itself, which is not T_CLASS, so a legitimate
            // `DateTimeImmutable ::class` (one space, valid PHP) was reported as a violation. The docblock
            // promises the opposite. A rule that cannot pass is as broken as one that cannot fail.
            $colonIndex = $index;

            while ($colonIndex < $count && !$tokens[$colonIndex]->is(\T_DOUBLE_COLON)) {
                ++$colonIndex;
            }

            $afterColon = nextMeaningfulToken($tokens, $colonIndex);

            if (null === $afterColon || !$afterColon->is(\T_CLASS)) {
                $violations[] = describe(
                    $file,
                    $token->line,
                    $class . '::',
                    'a static call on this class obtains one; ' . $reason,
                );
            }

            break;
        }

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
    return sprintf('%s:%d — %s is ambient. %s.', relative($file), $line, $what, $reason);
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
    $count = count($tokens);

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
