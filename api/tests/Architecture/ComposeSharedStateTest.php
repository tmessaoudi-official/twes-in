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
 * The share directory holds what must outlive a container: the rate limiters' counters and the scheduler's last runs.
 * Left inside the container, a redeploy hands every client a fresh budget and lets the scheduler forget what it ran, so
 * the api and the worker mount one named volume there, and the image creates the directory for the volume to start
 * owned by the user the container runs as.
 */
final class ComposeSharedStateTest extends TestCase
{
    private const string APP = '/app/';

    public function testTheApiAndTheWorkerKeepTheShareDirectoryOnOneVolume(): void
    {
        $share = self::shareDirectory();
        $compose = Yaml::parseFile(self::root().'/compose.yaml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);

        $volumes = [];
        foreach (['api', 'worker'] as $service) {
            $definition = $services[$service] ?? null;
            self::assertIsArray($definition, $service.' is in the compose file');
            $mounted = array_values(array_filter(
                \is_array($definition['volumes'] ?? null) ? $definition['volumes'] : [],
                static fn (mixed $mount): bool => \is_string($mount) && str_ends_with($mount, ':'.self::APP.$share),
            ));
            self::assertCount(1, $mounted, $service.' mounts a volume at '.self::APP.$share);
            $volumes[$service] = explode(':', (string) $mounted[0])[0];
        }

        self::assertSame($volumes['api'], $volumes['worker'], 'one budget and one schedule, whichever container reads it');
        $declared = $compose['volumes'] ?? null;
        self::assertIsArray($declared);
        self::assertArrayHasKey($volumes['api'], $declared, 'a named volume, which a redeploy keeps');
    }

    public function testTheImageCreatesTheShareDirectoryForTheVolumeToStartOwnedByItsUser(): void
    {
        $dockerfile = file_get_contents(self::root().'/infra/api/Dockerfile');
        self::assertIsString($dockerfile);

        self::assertMatchesRegularExpression('#mkdir -p [^\n]*\b'.preg_quote(self::shareDirectory(), '#').'\b#', $dockerfile);
    }

    /** Where the application keeps it, as `api/.env` says: `var/share`. */
    private static function shareDirectory(): string
    {
        $env = file_get_contents(self::root().'/api/.env');
        self::assertIsString($env);
        if (1 !== preg_match('/^APP_SHARE_DIR=(\S+)$/m', $env, $match)) {
            self::fail('api/.env names the share directory');
        }

        return $match[1];
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
