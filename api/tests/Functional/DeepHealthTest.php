<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/health/deep: what the monitor reads every minute (docs/START.md § 15). Only the bearer of the instance's
 * monitor token gets an answer; to anyone else, and on a machine with no token, it does not exist.
 */
final class DeepHealthTest extends WebTestCase
{
    private const string TOKEN = 'monitor-token-for-the-test-0123456789';

    protected function tearDown(): void
    {
        foreach (['MONITOR_TOKEN', 'BACKUP_EXPECTED'] as $name) {
            $_SERVER[$name] = $_ENV[$name] = '';
        }
        parent::tearDown();
    }

    public function testWithoutATokenConfiguredItDoesNotExist(): void
    {
        $client = self::client(token: '');
        $client->request('GET', '/api/health/deep', server: ['HTTP_AUTHORIZATION' => 'Bearer ']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAWrongOrMissingBearerFindsNothing(): void
    {
        $client = self::client();
        $client->request('GET', '/api/health/deep');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/health/deep', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN.'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMonitorReadsAHealthyServer(): void
    {
        $client = self::client();
        $body = self::read($client);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $body['status']);
        self::assertSame([], $body['failing']);
        self::assertSame(0, $body['readings']['failedMessages']);
        self::assertNull($body['readings']['oldestWaitingSeconds']);
        self::assertIsFloat($body['readings']['diskFree']);
        self::assertSame('not expected', $body['readings']['archiving']);
    }

    public function testAMessageThatFailedForGoodIsReported(): void
    {
        $client = self::client();
        self::queue($client, 'failed', '-1 hour');

        $body = self::read($client);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['failedMessages'], $body['failing']);
        self::assertSame(1, $body['readings']['failedMessages']);
    }

    public function testAMessageNobodyPicksUpIsReported(): void
    {
        $client = self::client();
        self::queue($client, 'async', '-11 minutes');
        // A retry scheduled for later is not waiting yet.
        self::queue($client, 'async', '+30 minutes');

        $body = self::read($client);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['oldestWaiting'], $body['failing']);
        self::assertGreaterThanOrEqual(660, $body['readings']['oldestWaitingSeconds']);
        self::assertLessThan(1800, $body['readings']['oldestWaitingSeconds']);
    }

    public function testARetryScheduledForLaterIsNotWaiting(): void
    {
        $client = self::client();
        self::queue($client, 'async', '+30 minutes');

        $body = self::read($client);

        self::assertResponseIsSuccessful();
        self::assertNull($body['readings']['oldestWaitingSeconds']);
    }

    public function testAMessageBeingHandledIsNotWaiting(): void
    {
        $client = self::client();
        self::queue($client, 'async', '-11 minutes', delivered: true);

        self::read($client);

        self::assertResponseIsSuccessful();
    }

    /** The test database archives nothing, as a machine whose backup layer is missing or broken would. */
    public function testWhereBackupsRunArchivingMustWork(): void
    {
        $client = self::client(backups: true);
        $body = self::read($client);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['archiving'], $body['failing']);
        self::assertSame('off', $body['readings']['archiving']);
    }

    public function testNoSessionIsOpened(): void
    {
        $client = self::client();
        self::read($client);

        self::assertResponseHeaderSame('cache-control', 'no-cache, private');
        self::assertNull($client->getResponse()->headers->getCookies()[0] ?? null);
    }

    private static function client(string $token = self::TOKEN, bool $backups = false): KernelBrowser
    {
        $_SERVER['MONITOR_TOKEN'] = $_ENV['MONITOR_TOKEN'] = $token;
        $_SERVER['BACKUP_EXPECTED'] = $_ENV['BACKUP_EXPECTED'] = $backups ? '1' : '0';

        return static::createClient();
    }

    /** @return array{status: string, failing: list<string>, readings: array<string, mixed>} */
    private static function read(KernelBrowser $client): array
    {
        $client->request('GET', '/api/health/deep', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);
        /** @var array{status: string, failing: list<string>, readings: array<string, mixed>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }

    private static function queue(KernelBrowser $client, string $queue, string $availableIn, bool $delivered = false): void
    {
        $connection = $client->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        // Placed on the database's clock, which the reading measures with: inside the test's transaction that clock
        // stands at the transaction's start, before this line runs, so a time taken from PHP's clock reads younger.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
                SELECT '{}', '[]', :queue, at, at, CASE WHEN :delivered THEN at END
                FROM (SELECT (now() AT TIME ZONE 'UTC') + CAST(:availableIn AS interval) AS at) placed
                SQL,
            ['queue' => $queue, 'availableIn' => $availableIn, 'delivered' => $delivered],
            ['delivered' => ParameterType::BOOLEAN],
        );
    }
}
