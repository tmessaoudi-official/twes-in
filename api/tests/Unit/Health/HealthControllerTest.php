<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\DatabaseProbe;
use App\Health\HealthController;
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

        $response = (new HealthController($probe))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'degraded', 'database' => 'unreachable'], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAReachableDatabaseYieldsOk(): void
    {
        $probe = new class implements DatabaseProbe {
            public function isReachable(): bool
            {
                return true;
            }
        };

        $response = (new HealthController($probe))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'database' => 'ok'], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }
}
