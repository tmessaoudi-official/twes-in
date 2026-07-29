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

            continue;
        }

        // exit and die are language constructs, not T_STRING function names.
        if ($token->is([\T_EXIT])) {
            $violations[] = describe($file, $token->line, strtolower($token->text), BANNED_FUNCTIONS['exit']);

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
            $literal = strtolower(trim($token->text, "'\""));

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
