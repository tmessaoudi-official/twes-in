<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Audit\Application\AuditVocabulary;
use App\Erasure\Application\ErasureCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A screen reads again when a kind of record changes (`reloadOn`, `liveRecord`, `listRecord`, `LiveChanges.on`), and
 * hears only the kinds the API stages: every heard audit action stages its entry's kind (`DoctrineAuditTrail`), and a
 * use case whose change is not audited stages its own `LiveChange`. A kind a screen listens to that nothing stages
 * leaves that screen silently stale, with no error anywhere (`ManageRoles` once recorded nothing). The writer's half,
 * every command recording its audit entry, is CommandsAndQueriesTest's; this is the contract between the two sides:
 * every kind a screen listens to is one the API stages. A kind the API stages that no screen hears is no defect.
 */
final class ListenedKindsStagedTest extends KernelTestCase
{
    /** Where a screen listens to kinds handed to it rather than written there, and where those kinds are held. */
    private const array LISTENED_HANDED = [
        'web/src/app/shared/form/live-record.ts' => 'liveRecord, which hands its kind to on(); every call names its own, read here',
        'web/src/app/shared/form/list-record.ts' => 'listRecord, which hands its kind to liveRecord; every call names its own, read here',
        'web/src/app/activity/record-history.ts' => "a record's history, listening to the kind its page names, one ACTIVITY_KINDS lists (AuditActionWordsTest)",
    ];

    /** Where the API stages a kind it reads at run time, and where those kinds come from. */
    private const array STAGED_AT_RUN_TIME = [
        'api/src/Audit/Infrastructure/DoctrineAuditTrail.php' => 'the kind of every heard audit entry, read here from AuditVocabulary',
        'api/src/Records/Application/RestoreRecords.php' => 'the tables a restored merge wrote again, each also a deletable subject audited by its own use case',
        'api/src/Records/Application/Merge/MergeRecords.php' => 'the tables a merge wrote, each also a deletable subject audited by its own use case',
        'api/src/Erasure/Application/EraseData.php' => 'the kinds the erasure catalogue names for the parts chosen, read here from ErasureCatalogue',
        'api/src/Erasure/Application/UndoErasure.php' => 'the kinds the erasure catalogue names for the parts put back, read here from ErasureCatalogue',
    ];

    /** One argument, read whole: an array literal, a quoted kind, or a name (`this.kind()` included). */
    private const string ARGUMENT = '((?>\[[^\]]*\]|\'[a-z_.]*\'|[A-Za-z_][A-Za-z0-9_.]*(?:\(\))?))';

    /** @var array{kinds: array<string, list<string>>, handed: list<string>}|null */
    private static ?array $listened = null;

    public function testEveryKindAScreenListensToIsOneTheApiStages(): void
    {
        $staged = $this->staged();
        $unstaged = [];
        foreach (self::listened()['kinds'] as $kind => $where) {
            if (!isset($staged[$kind])) {
                $unstaged[] = \sprintf('%s (%s)', $kind, implode(', ', $where));
            }
        }

        // Fifty kinds were listened to when this was written: losing a way of listening, or the constants, reds.
        self::assertGreaterThanOrEqual(45, \count(self::listened()['kinds']), 'the reading finds the kinds the screens listen to');
        self::assertGreaterThanOrEqual(45, \count($staged), 'the reading finds the kinds the API stages');
        self::assertSame([], $unstaged, 'a screen listens to these kinds, which nothing in the API stages: record an audit action on the kind or stage a LiveChange of it');
    }

    public function testWhatIsHandedAtRunTimeIsListedAndWhatIsListedStillIs(): void
    {
        $handed = self::listened()['handed'];
        self::assertSame([], array_values(array_diff($handed, array_keys(self::LISTENED_HANDED))), 'these screens listen to kinds this test cannot read: name them, or list the file with where its kinds are held');
        self::assertSame([], array_values(array_diff(array_keys(self::LISTENED_HANDED), $handed)), 'these files no longer listen to a handed kind: strike them off');

        $runTime = self::stagedLiterally()['runTime'];
        self::assertSame([], array_values(array_diff($runTime, array_keys(self::STAGED_AT_RUN_TIME))), 'these stage a kind this test cannot read: name it with a literal or a constant, or list the file with where its kinds come from');
        self::assertSame([], array_values(array_diff(array_keys(self::STAGED_AT_RUN_TIME), $runTime)), 'these files no longer stage a kind read at run time: strike them off');
    }

    /** @return array<string, true> every kind the API stages */
    private function staged(): array
    {
        $vocabulary = static::getContainer()->get(AuditVocabulary::class);
        self::assertInstanceOf(AuditVocabulary::class, $vocabulary);
        $staged = [];
        foreach (array_keys($vocabulary->all()) as $action) {
            if ($vocabulary->heard($action)) {
                foreach ($vocabulary->kindsOf($action) as $kind) {
                    $staged[$kind] = true;
                }
            }
        }
        $erasure = static::getContainer()->get(ErasureCatalogue::class);
        self::assertInstanceOf(ErasureCatalogue::class, $erasure);
        foreach ([...$erasure->liveKindsOf(ErasureCatalogue::PARTS), ...self::stagedLiterally()['kinds']] as $kind) {
            $staged[$kind] = true;
        }

        return $staged;
    }

    /** @return array{kinds: list<string>, runTime: list<string>} the kinds `new LiveChange(` and `LiveChange::everyone(` name, and the files staging one read at run time */
    private static function stagedLiterally(): array
    {
        $root = \dirname(__DIR__, 3);
        $kinds = [];
        $runTime = [];
        foreach (self::files($root.'/api/src', '.php') as $path) {
            $source = self::read($path);
            if (0 === preg_match_all('/(?:new LiveChange|LiveChange::everyone)\(\s*([^,]+),/', $source, $calls)) {
                continue;
            }
            $relative = substr($path, \strlen($root) + 1);
            foreach ($calls[1] as $argument) {
                $kind = self::phpValue(trim($argument), $path, $source);
                if (null === $kind) {
                    $runTime[$relative] = $relative;
                } else {
                    $kinds[] = $kind;
                }
            }
        }

        return ['kinds' => array_values(array_unique($kinds)), 'runTime' => array_values($runTime)];
    }

    /** A quoted kind, or a class constant named on this class or on one the file imports; null for what is read at run time. */
    private static function phpValue(string $argument, string $path, string $source): ?string
    {
        if (1 === preg_match("/^'([a-z_.]+)'$/", $argument, $quoted)) {
            return $quoted[1];
        }
        if (1 !== preg_match('/^(self|static|[A-Z]\w*)::([A-Z_]+)$/', $argument, $constant)) {
            return null;
        }
        if ('self' === $constant[1] || 'static' === $constant[1]) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen(\dirname(__DIR__, 2).'/src') + 1, -4));
        } else {
            self::assertSame(1, preg_match('/^use ([\w\\\\]+\\\\'.$constant[1].');$/m', $source, $imported), $path.' names '.$constant[1].' without importing it');
            $class = $imported[1];
        }
        $value = \constant($class.'::'.$constant[2]);
        self::assertIsString($value, $argument);

        return $value;
    }

    /** @return array{kinds: array<string, list<string>>, handed: list<string>} every kind a screen listens to, with where, and the files listening to kinds handed to them */
    private static function listened(): array
    {
        if (null !== self::$listened) {
            return self::$listened;
        }
        $root = \dirname(__DIR__, 3);
        $sources = [];
        foreach (self::files($root.'/web/src/app', '.ts') as $path) {
            if (!str_ends_with($path, '.spec.ts')) {
                $sources[substr($path, \strlen($root) + 1)] = self::read($path);
            }
        }
        $constants = self::constants($sources);
        $kinds = [];
        $handed = [];
        foreach ($sources as $file => $source) {
            $arguments = [];
            // A listener's first argument; a declaration's typed parameter (`kinds: readonly string[]`) is none.
            preg_match_all('/(?:reloadOn|withSessionKinds|LiveChanges\)\.on|live\.on)\(\s*'.self::ARGUMENT.'(?!\s*:)/', $source, $calls);
            array_push($arguments, ...$calls[1]);
            preg_match_all('/(?:liveRecord|listRecord)\(\{.{0,1500}?\bkind:\s*'.self::ARGUMENT.'/s', $source, $records);
            array_push($arguments, ...$records[1]);
            if (str_contains($source, 'withSessionKinds(')) {
                $arguments[] = 'SESSION_KINDS';
            }
            foreach ($arguments as $argument) {
                $named = self::webKinds($argument, $constants);
                if (null === $named) {
                    $handed[$file] = $file;
                    continue;
                }
                foreach ($named as $kind) {
                    $kinds[$kind][$file] = $file;
                }
            }
        }
        ksort($kinds);

        return self::$listened = ['kinds' => array_map(array_values(...), $kinds), 'handed' => array_values($handed)];
    }

    /**
     * The kinds an argument names: quoted kinds, constants declared with them (spread or not) and a nested
     * `withSessionKinds(`, read through its own call; null when any part is handed at run time.
     *
     * @param array<string, list<string>> $constants
     *
     * @return list<string>|null
     */
    private static function webKinds(string $argument, array $constants): ?array
    {
        if ('withSessionKinds' === $argument) {
            return [];
        }
        $parts = str_starts_with($argument, '[') ? preg_split('/\s*,\s*/', trim($argument, "[] \n")) : [$argument];
        $kinds = [];
        foreach (false === $parts ? [] : $parts as $part) {
            $part = preg_replace('/^\.\.\./', '', trim($part)) ?? '';
            if ('' === $part) {
                continue;
            }
            if (1 === preg_match("/^'([a-z_.]+)'$/", $part, $quoted)) {
                $kinds[] = $quoted[1];
            } elseif (isset($constants[$part])) {
                array_push($kinds, ...$constants[$part]);
            } else {
                return null;
            }
        }

        return $kinds;
    }

    /**
     * Every constant the web declares as one quoted kind or an array of them.
     *
     * @param array<string, string> $sources
     *
     * @return array<string, list<string>>
     */
    private static function constants(array $sources): array
    {
        $constants = [];
        foreach ($sources as $source) {
            preg_match_all('/\bconst ([A-Z][A-Z0-9_]*)(?:\s*:\s*[^=]+)?\s*=\s*(\'[a-z_.]+\'|\[[^\]]*\])/', $source, $declared, \PREG_SET_ORDER);
            foreach ($declared as [, $name, $value]) {
                preg_match_all("/'([a-z_.]+)'/", $value, $quoted);
                $constants[$name] = [...$constants[$name] ?? [], ...$quoted[1]];
            }
        }

        return $constants;
    }

    /** @return list<string> */
    private static function files(string $directory, string $suffix): array
    {
        self::assertDirectoryExists($directory);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
            if (str_ends_with($path, $suffix)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }
}
