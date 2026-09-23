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
 * docs/SPEC.md § 8 row 22, review S11: the development stack's backing services are published to this machine, not
 * to the network it is on. A `"5433:5432"` binds every interface, so a laptop's database, its mail and everything
 * that has passed through them are readable by anyone sharing a café's wifi; `"127.0.0.1:5433:5432"` serves the
 * host exactly as well, which is all any of them is for.
 *
 * Read from the compose file itself, so a service added later is covered without being listed here.
 */
final class ComposePortsTest extends TestCase
{
    /** What is published on every interface on purpose, and why. */
    private const array ON_EVERY_INTERFACE = [
        'web' => 'the application, opened from a browser and driven by Playwright',
        'api' => 'the application, opened from a browser and driven by Playwright',
        // docs/SPEC.md § 7, 2026-09-23 14:08: reaching this machine from the network is its whole purpose.
        'lan' => 'the HTTPS door and its certificate root, for a phone on the local network; behind the lan profile',
    ];

    public function testTheBackingServicesArePublishedToThisMachineAlone(): void
    {
        $compose = Yaml::parseFile(\dirname(__DIR__, 3).'/compose.yaml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? [];
        self::assertIsArray($services);

        $published = [];
        foreach ($services as $name => $definition) {
            $ports = \is_array($definition) ? $definition['ports'] ?? null : null;
            if (!\is_array($ports)) {
                continue;
            }
            foreach ($ports as $mapping) {
                // Compose also allows a mapping written as a map; none is written that way here, and the floor
                // below is what notices if that ever stops being true rather than this quietly reading nothing.
                if (\is_string($mapping)) {
                    $published[] = [(string) $name, $mapping];
                }
            }
        }

        // Without this the whole check passes on a compose file that publishes nothing, or one this stopped reading.
        self::assertGreaterThan(4, \count($published), 'the compose file publishes its ports where this can read them');

        $reachable = [];
        foreach ($published as [$name, $mapping]) {
            if (isset(self::ON_EVERY_INTERFACE[$name]) || str_starts_with($mapping, '127.0.0.1:')) {
                continue;
            }
            $reachable[] = $name.' '.$mapping;
        }

        self::assertSame([], $reachable, 'a backing service is published to every interface, not just this machine');
    }
}
