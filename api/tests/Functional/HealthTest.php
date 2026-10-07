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
        // An image built with no version (here, the test kernel) says so rather than inventing one.
        self::assertSame(['status' => 'ok', 'database' => 'ok', 'build' => ['version' => null, 'commit' => null, 'mode' => 'test'], 'deployment' => null], $body);
    }

    /**
     * The footer shows the API's build beside the web's (docs/SPEC.md § 7, the build line): the version and commit the
     * image was built as, the environment the kernel runs in, and the deployment, each as it is; the web leaves out
     * what reads « prod ».
     */
    public function testHealthSaysWhichBuildAnswersAndWhere(): void
    {
        $values = ['BUILD_VERSION' => '2026.10.07.5-dirty', 'BUILD_COMMIT' => 'a7adf55f', 'DEPLOY_ENV' => 'staging'];
        foreach ($values as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        try {
            $client = static::createClient();
            $client->request('GET', '/api/health');
        } finally {
            foreach (array_keys($values) as $name) {
                $_SERVER[$name] = $_ENV[$name] = '';
            }
        }

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['version' => '2026.10.07.5-dirty', 'commit' => 'a7adf55f', 'mode' => 'test'], $body['build'] ?? null);
        self::assertSame('staging', $body['deployment'] ?? null);
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
