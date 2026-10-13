<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A service of the production stack that stops, the database after a reboot or the API after a crash, stays stopped
 * until someone notices: on a shop's counter that is the morning lost. Every service the production stack starts
 * comes back by itself, unless it was stopped on purpose.
 *
 * Read from the two compose files as compose merges them (compose.yaml, then compose.prod.yaml over it), so a service
 * added later is covered without being listed here. A service behind a profile is started only when asked for, and is
 * not part of the stack.
 */
final class ProductionRestartTest extends TestCase
{
    private const array COMES_BACK = ['unless-stopped', 'always'];

    /**
     * Run to completion and left down on purpose: restarted, it would run again for ever. After a reboot the services it
     * unblocked come back by themselves on the schema it left.
     */
    private const array ONE_SHOT = ['migrate'];

    public function testEveryServiceOfTheProductionStackComesBackByItself(): void
    {
        $root = \dirname(__DIR__, 3);
        $base = self::services($root.'/compose.yaml');
        $production = self::services($root.'/compose.prod.yaml');

        $started = [];
        foreach ($base as $name => $definition) {
            if (isset($definition['profiles'])) {
                continue;
            }
            $started[$name] = $production[$name]['restart'] ?? $definition['restart'] ?? null;
        }

        // Without this the check passes on a compose file this stopped reading.
        self::assertGreaterThan(5, \count($started), 'the compose files list the stack where this can read them');

        $staysDown = [];
        $restarted = [];
        foreach ($started as $name => $restart) {
            if (\in_array($name, self::ONE_SHOT, true)) {
                if (null !== $restart && 'no' !== $restart) {
                    $restarted[] = $name;
                }
            } elseif (!\in_array($restart, self::COMES_BACK, true)) {
                $staysDown[] = $name;
            }
        }

        self::assertSame([], $staysDown, 'these services stay down after a crash or a reboot');
        self::assertSame([], $restarted, 'these run once and must not be started again');
    }

    /**
     * The backup overlay runs over the production stack: a backup service that stays down after a reboot loses every
     * night's copy from then on without a sound, and the database it rebuilds must come back as the plain one does.
     */
    public function testEveryServiceTheBackupOverlayStartsComesBackByItself(): void
    {
        $backup = self::services(\dirname(__DIR__, 3).'/compose.backup.yaml');

        self::assertArrayHasKey('backup', $backup, 'the overlay starts the backup service');
        self::assertArrayHasKey('postgres', $backup, 'the overlay rebuilds the database it backs up');
        self::assertSame([], self::stayingDown($backup), 'these services stay down after a crash or a reboot');
    }

    public function testEveryServiceTheDeployOverlayStartsComesBackByItself(): void
    {
        $deploy = self::services(\dirname(__DIR__, 3).'/compose.deploy.yaml');

        self::assertArrayHasKey('edge', $deploy, 'the overlay starts the edge every request comes through');
        // It lies over the backup overlay, which starts the backup service it gives the monitor's values to.
        self::assertSame([], self::stayingDown($deploy, 'compose.backup.yaml'), 'these services stay down after a crash or a reboot');
    }

    /**
     * An overlay's services that would stay down, each read through the files beneath it: a restart policy set in
     * compose.yaml, compose.prod.yaml or an overlay beneath it still holds for a service the overlay only adjusts.
     *
     * @param array<array-key, array<array-key, mixed>> $overlay
     *
     * @return list<array-key>
     */
    private static function stayingDown(array $overlay, string ...$beneath): array
    {
        $root = \dirname(__DIR__, 3);
        $base = self::services($root.'/compose.yaml');
        $production = self::services($root.'/compose.prod.yaml');
        $layers = array_map(static fn (string $file): array => self::services($root.'/'.$file), $beneath);

        $staysDown = [];
        foreach ($overlay as $name => $definition) {
            if (isset($definition['profiles']) || isset($base[$name]['profiles']) || \in_array($name, self::ONE_SHOT, true)) {
                continue;
            }
            $restart = $definition['restart'] ?? null;
            foreach ($layers as $layer) {
                $restart ??= $layer[$name]['restart'] ?? null;
            }
            $restart ??= $production[$name]['restart'] ?? $base[$name]['restart'] ?? null;
            if (!\in_array($restart, self::COMES_BACK, true)) {
                $staysDown[] = $name;
            }
        }

        return $staysDown;
    }

    /** @return array<array-key, array<array-key, mixed>> */
    private static function services(string $file): array
    {
        // Compose's own tags (`!reset`, `!override`) are read as tagged values, which no assertion here looks into.
        $compose = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $services = $compose['services'] ?? [];
        self::assertIsArray($services);

        $read = [];
        foreach ($services as $name => $definition) {
            self::assertIsArray($definition, $name.' is a map in '.basename($file));
            $read[$name] = $definition;
        }

        return $read;
    }
}
