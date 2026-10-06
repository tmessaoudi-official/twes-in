<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthReportsOkWhenTheDatabaseIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['status' => 'ok', 'database' => 'ok'], $body);
    }

    /**
     * A caller with no session gets none read or started: the answer keeps Symfony's own header, which the web tier
     * passes through (web/e2e/auth.spec.ts), and nothing on the way asks who is signed in.
     */
    public function testACallerWithNoSessionHasNoneOpened(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseHeaderSame('cache-control', 'no-cache, private');
        self::assertNull($client->getResponse()->headers->getCookies()[0] ?? null);
    }
}
