<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SupportSettings;
use App\Shared\Infrastructure\Health\DatabaseProbe;
use App\Shared\Infrastructure\Health\HealthController;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testAnUnreachableDatabaseYieldsServiceUnavailable(): void
    {
        $probe = new class implements DatabaseProbe {
            public function isReachable(): bool
            {
                return false;
            }
        };

        // No setting declared at all: reading one would throw, so the status page is not asked of a database that is down.
        $response = (new HealthController($probe, self::settings(), '2026.10.07.5', 'a7adf55f', 'prod', ''))();

        self::assertSame(503, $response->getStatusCode());
        // Still says which build answers: the footer reads it from a degraded API too.
        self::assertSame(['status' => 'degraded', 'database' => 'unreachable', 'build' => ['version' => '2026.10.07.5', 'commit' => 'a7adf55f', 'mode' => 'prod'], 'deployment' => null, 'statusUrl' => null], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAReachableDatabaseYieldsOk(): void
    {
        $probe = new class implements DatabaseProbe {
            public function isReachable(): bool
            {
                return true;
            }
        };

        $response = (new HealthController($probe, self::settings(new SupportSettings()), '2026.10.07.5', 'a7adf55f', 'prod', ''))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'database' => 'ok', 'build' => ['version' => '2026.10.07.5', 'commit' => 'a7adf55f', 'mode' => 'prod'], 'deployment' => null, 'statusUrl' => null], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    private static function settings(SupportSettings ...$declared): ReadSetting
    {
        return new ReadSetting(new ResolveSettings(new SettingCatalog($declared), new InMemorySettings()));
    }
}
