<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Realtime;

use App\Erasure\Application\ErasureCatalogue;
use App\Records\Application\RecordCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A screen reads again on the kinds of change it names (`reloadOn`, `LiveChanges.on`, `liveRecord`, `listRecord`), and
 * the API says a change under the kind its audit entry, its staged live change, a record's removal or an erasure names.
 * A kind the web hears that nothing says is a dead listener, which reads as a screen kept fresh and is not. A kind said
 * that no screen hears leaves every screen showing it stale: the « Imports récents » list never heard an import kept,
 * taken back or put back (workflow hunt, freshness § 4).
 */
final class HeardKindsTest extends KernelTestCase
{
    /** A kind said that no screen hears, each with why no screen needs to. */
    private const array UNHEARD = [
        'legal_text' => 'written by the operators with no company, and not a platform kind: the legal pages read it when opened',
    ];

    public function testEveryKindAScreenHearsIsSaidAndEveryKindSaidIsHeard(): void
    {
        $root = \dirname(__DIR__, 4);
        $said = $this->said($root);
        $heard = $this->heard($root);

        self::assertGreaterThanOrEqual(45, \count($said), 'the API says its kinds');
        self::assertGreaterThanOrEqual(45, \count($heard), 'the web hears its kinds');
        foreach ($heard as $kind => $where) {
            self::assertArrayHasKey($kind, $said, \sprintf('%s hears the kind %s, which nothing in the API says', $where, $kind));
        }
        foreach ($said as $kind => $where) {
            if (!\array_key_exists($kind, $heard)) {
                self::assertArrayHasKey($kind, self::UNHEARD, \sprintf('%s says the kind %s, which no screen hears: name it in a reloadOn, or here with why', $where, $kind));
            }
        }
        foreach (array_keys(self::UNHEARD) as $kind) {
            self::assertArrayNotHasKey($kind, $heard, "$kind is heard now: take it off the list");
            self::assertArrayHasKey($kind, $said, "$kind is no longer said: take it off the list");
        }
    }

    /**
     * Each kind the API says, by the file saying it: a literal or a class constant handed to an audit entry or a live
     * change, a venue's typed record, an erasure's live kind, a record subject's table.
     *
     * @return array<string, string>
     */
    private function said(string $root): array
    {
        $files = $this->files($root.'/api/src', 'php');
        $constants = [];
        foreach ($files as $file) {
            preg_match_all("/const string ([A-Z_]+) = '([^']*)'/", (string) file_get_contents($file), $found, \PREG_SET_ORDER);
            foreach ($found as [, $name, $value]) {
                $constants[basename($file, '.php').'::'.$name] = $value;
            }
        }
        $said = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/new (?:LiveChange|AuditEntry)\\(\\s*('[a-z_]+'|\\w+::[A-Z_]+),/", $source, $handed);
            preg_match_all('/\$this->record\(\s*(self::[A-Z_]+_TYPE),/', $source, $typed);
            foreach ([...$handed[1], ...$typed[1]] as $argument) {
                if (str_starts_with($argument, "'")) {
                    $said[trim($argument, "'")] ??= $this->relative($root, $file);
                    continue;
                }
                [$class, $name] = explode('::', $argument);
                $class = 'self' === $class || 'static' === $class ? basename($file, '.php') : $class;
                $value = $constants["$class::$name"] ?? null;
                self::assertNotNull($value, \sprintf('%s says the kind %s, which this test cannot read', $this->relative($root, $file), $argument));
                $said[$value] ??= $this->relative($root, $file);
            }
        }
        foreach (static::getContainer()->get(ErasureCatalogue::class)->liveKindsOf(ErasureCatalogue::PARTS) as $kind) {
            $said[$kind] ??= 'an erasure';
        }
        foreach (array_keys(static::getContainer()->get(RecordCatalogue::class)->subjects()) as $kind) {
            $said[$kind] ??= 'a record removed, restored or merged';
        }

        return $said;
    }

    /**
     * Each kind a screen hears, by the file hearing it, a constant named there read where it is declared.
     *
     * @return array<string, string>
     */
    private function heard(string $root): array
    {
        $files = array_filter($this->files($root.'/web/src/app', 'ts'), static fn (string $file): bool => !str_ends_with($file, '.spec.ts'));
        $constants = [];
        foreach ($files as $file) {
            preg_match_all("/const ([A-Z][A-Z_]+)\\b[^=;]*=\\s*(\\[[^\\]]*\\]|'[a-z_]+')/", (string) file_get_contents($file), $found, \PREG_SET_ORDER);
            foreach ($found as [, $name, $value]) {
                $constants[$name] = $value;
            }
        }
        $heard = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $where = $this->relative($root, $file);
            preg_match_all('/(?:reloadOn|\\.on)\\(\\s*(\\[[^\\]]*\\]|[A-Z][A-Z_]+)/', $source, $found);
            $arguments = $found[1];
            preg_match_all('/(?:liveRecord|listRecord)\(\{/', $source, $records, \PREG_OFFSET_CAPTURE);
            foreach ($records[0] as [$call, $at]) {
                $options = substr($source, $at + \strlen($call), 400);
                if (1 === preg_match("/\\bkind:\\s*(\\[[^\\]]*\\]|'[a-z_]+'|[A-Z][A-Z_]+)/", $options, $kind)) {
                    $arguments[] = $kind[1];
                } elseif (!str_ends_with($file, '/shared/form/list-record.ts')) {
                    self::fail("$where keeps a record live without a kind this test can read");
                }
            }
            foreach ($arguments as $argument) {
                $this->take($argument, $where, $constants, $heard);
            }
        }

        return $heard;
    }

    /**
     * @param array<string, string> $constants
     * @param array<string, string> $heard
     */
    private function take(string $argument, string $where, array $constants, array &$heard): void
    {
        preg_match_all("/'([a-z_]+)'/", $argument, $literals);
        foreach ($literals[1] as $kind) {
            $heard[$kind] ??= $where;
        }
        preg_match_all('/\b([A-Z][A-Z_]+)\b/', $argument, $names);
        foreach ($names[1] as $name) {
            self::assertArrayHasKey($name, $constants, "$where hears the kinds of $name, which this test cannot find");
            $this->take($constants[$name], "$where ($name)", $constants, $heard);
        }
    }

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $extension === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $root, string $file): string
    {
        return substr($file, \strlen($root) + 1);
    }
}
