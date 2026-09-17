<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Shared\Application\RealtimePublisher;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Centrifugo's HTTP publish API, reached from the api container only (docs/SPEC.md § 7, 2026-09-13). Best-effort:
 * an unreachable server, an HTTP error or a refusal in the body is logged as a warning and never thrown, because
 * the notification is already stored and the use case that published it has succeeded.
 */
#[WithMonologChannel('realtime')]
final readonly class CentrifugoPublisher implements RealtimePublisher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiKey,
        private bool $enabled,
        private float $timeoutSeconds = 2.0,
    ) {
    }

    public function push(string $channel, array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $body = $this->httpClient->request('POST', rtrim($this->apiUrl, '/').'/publish', [
                'headers' => ['X-API-Key' => $this->apiKey],
                'json' => ['channel' => $channel, 'data' => $data],
                'timeout' => $this->timeoutSeconds,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->warn($channel, $e->getMessage());

            return;
        }

        if (isset($body['error'])) {
            $this->warn($channel, json_encode($body['error'], \JSON_UNESCAPED_SLASHES) ?: 'unreadable error');
        }
    }

    private function warn(string $channel, string $reason): void
    {
        $this->logger->warning('realtime push to {channel} failed: {reason}', ['channel' => $channel, 'reason' => $reason]);
    }
}
