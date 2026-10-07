<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Health\DatabaseProbe;
use App\Shared\Infrastructure\Health\HealthController;
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

        $response = (new HealthController($probe, '2026.10.07.5', 'a7adf55f', 'prod', ''))();

        self::assertSame(503, $response->getStatusCode());
        // Still says which build answers: the footer reads it from a degraded API too.
        self::assertSame(['status' => 'degraded', 'database' => 'unreachable', 'build' => ['version' => '2026.10.07.5', 'commit' => 'a7adf55f', 'mode' => 'prod'], 'deployment' => null], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAReachableDatabaseYieldsOk(): void
    {
        $probe = new class implements DatabaseProbe {
            public function isReachable(): bool
            {
                return true;
            }
        };

        $response = (new HealthController($probe, '2026.10.07.5', 'a7adf55f', 'prod', ''))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'database' => 'ok', 'build' => ['version' => '2026.10.07.5', 'commit' => 'a7adf55f', 'mode' => 'prod'], 'deployment' => null], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }
}
