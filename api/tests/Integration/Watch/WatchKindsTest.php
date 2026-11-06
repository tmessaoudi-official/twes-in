<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Watch;

use App\Records\Application\RecordCatalogue;
use App\Watch\Application\ComesDueWithTime;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchCatalogue;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « À surveiller » is read again on the kinds of change its declarations name (`DeclaresWatch::changedBy`), which the
 * web lists as `WATCHED_KINDS` (web/src/app/watch/watch-types.ts): a kind a declaration reads and the web does not hear
 * leaves the count stale until a reload, and a kind the web hears that nothing says is dead. Each is a kind the API
 * says: a recorded subject, a staged live change, or a record subject's table, which deleting and restoring say.
 */
final class WatchKindsTest extends KernelTestCase
{
    public function testTheWebHearsExactlyTheKindsTheDeclarationsReadAndEachIsSaid(): void
    {
        $read = static::getContainer()->get(WatchCatalogue::class)->changedBy();
        $root = \dirname(__DIR__, 4);
        $web = (string) file_get_contents($root.'/web/src/app/watch/watch-types.ts');
        if (1 !== preg_match('/WATCHED_KINDS = \[(.*?)\] as const;/s', $web, $list)) {
            self::fail('WATCHED_KINDS is not found in watch-types.ts');
        }
        preg_match_all("/'([a-z_]+)'/", $list[1], $heard);
        $heard = $heard[1];
        sort($heard);

        self::assertGreaterThanOrEqual(9, \count($read), 'the declarations are read');
        self::assertSame($read, $heard);
        $said = array_keys(static::getContainer()->get(RecordCatalogue::class)->subjects());
        // A kind is said by an entity type, or handed to a live change by `new` or a named constructor
        // (`LiveChange::everyone`), as a literal or a constant, read as HeardKindsTest reads them.
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/api/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        $constants = [];
        foreach ($files as $path => $source) {
            preg_match_all("/const string ([A-Z_]+) = '([^']*)'/", $source, $found, \PREG_SET_ORDER);
            foreach ($found as [, $name, $value]) {
                $constants[basename($path, '.php').'::'.$name] = $value;
            }
        }
        foreach ($files as $path => $source) {
            preg_match_all("/ENTITY_TYPE = '([a-z_]+)'/", $source, $types);
            preg_match_all("/(?:new LiveChange|LiveChange::\\w+)\\(\\s*('[a-z_]+'|\\w+::[A-Z_]+)/", $source, $handed);
            $said = [...$said, ...$types[1]];
            foreach ($handed[1] as $argument) {
                if (str_starts_with($argument, "'")) {
                    $said[] = trim($argument, "'");
                    continue;
                }
                [$class, $name] = explode('::', $argument);
                $class = 'self' === $class || 'static' === $class ? basename($path, '.php') : $class;
                $said[] = $constants["$class::$name"] ?? '';
            }
        }
        foreach ($read as $kind) {
            self::assertContains($kind, $said, "nothing says the kind $kind");
        }
    }

    /**
     * A part counting from the clock rather than from the company's day (a draft waiting past its hours, an invitation
     * lapsing) changes with nothing written, between the day's turns: it says when (`ComesDueWithTime`), or a screen left
     * open keeps the count it read. A part counting from the day alone is read again as the day turns.
     */
    public function testEveryPartReadingTheClockSaysWhenItChangesWithTimeAlone(): void
    {
        $declarations = new \ReflectionProperty(WatchCatalogue::class, 'declarations')->getValue(static::getContainer()->get(WatchCatalogue::class));
        self::assertIsArray($declarations);
        $timed = [];
        foreach ($declarations as $declaration) {
            self::assertInstanceOf(DeclaresWatch::class, $declaration);
            $constructor = new \ReflectionClass($declaration)->getConstructor();
            $readsClock = null !== $constructor && [] !== array_filter(
                $constructor->getParameters(),
                static fn (\ReflectionParameter $parameter): bool => ClockInterface::class === (string) $parameter->getType(),
            );
            if ($readsClock) {
                self::assertInstanceOf(ComesDueWithTime::class, $declaration, $declaration::class.' reads the clock and says nothing of when it changes');
                $timed[] = $declaration->key();
            }
        }
        sort($timed);

        self::assertSame(['delivery_note', 'members'], $timed, 'the parts reading the clock today');
    }
}
