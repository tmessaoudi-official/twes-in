<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Realtime\CentrifugoPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Centrifugo's HTTP publish API. The push is best-effort (docs/SPEC.md § 7, 2026-09-13): whatever Centrifugo or
 * the network does, the caller carries on, and the failure is logged where somebody can see it.
 */
#[CoversClass(CentrifugoPublisher::class)]
final class CentrifugoPublisherTest extends TestCase
{
    private const string URL = 'http://centrifugo:8000/api';
    private const string KEY = 'a-development-api-key';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testItPublishesTheDataToTheChannelWithTheApiKey(): void
    {
        $response = new MockResponse('{"result":{}}');

        $this->publisher(new MockHttpClient($response))->push('user:0198f0c4-8a3e-7b2c-9d1e-2f3a4b5c6d7e', ['type' => 'membership.added', 'payload' => ['company' => 'Acme']]);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::URL.'/publish', $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertIsArray($options['headers']);
        self::assertContains('X-API-Key: '.self::KEY, $options['headers']);
        self::assertIsString($options['body']);
        self::assertSame(
            ['channel' => 'user:0198f0c4-8a3e-7b2c-9d1e-2f3a4b5c6d7e', 'data' => ['type' => 'membership.added', 'payload' => ['company' => 'Acme']]],
            json_decode($options['body'], true, 8, \JSON_THROW_ON_ERROR),
        );
        self::assertSame([], $this->logger->records);
    }

    public function testAnUnreachableServerIsLoggedAndNotThrown(): void
    {
        $this->publisher(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])))->push('user:x', []);

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame('user:x', $this->logger->records[0]['context']['channel']);
    }

    public function testAnHttpErrorIsLoggedAndNotThrown(): void
    {
        $this->publisher(new MockHttpClient(new MockResponse('unauthorized', ['http_code' => 401])))->push('user:x', []);

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testAnErrorCentrifugoReportsInsideA200IsLoggedAndNotThrown(): void
    {
        // Centrifugo's default error mode answers 200 and puts the refusal in the body.
        $this->publisher(new MockHttpClient(new MockResponse('{"error":{"code":102,"message":"unknown channel"}}')))->push('user:x', []);

        self::assertCount(1, $this->logger->records);
        $reason = $this->logger->records[0]['context']['reason'];
        self::assertIsString($reason);
        self::assertStringContainsString('unknown channel', $reason);
    }

    public function testWhenDisabledNothingIsSent(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('no request is made while pushes are disabled');
        });

        $this->publisher($client, enabled: false)->push('user:x', []);

        self::assertSame(0, $client->getRequestsCount());
    }

    private function publisher(MockHttpClient $client, bool $enabled = true): CentrifugoPublisher
    {
        return new CentrifugoPublisher($client, $this->logger, self::URL, self::KEY, $enabled);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) ? $level : get_debug_type($level), 'message' => (string) $message, 'context' => $context];
    }
}
