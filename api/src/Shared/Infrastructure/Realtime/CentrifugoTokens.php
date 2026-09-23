<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Shared\Application\RealtimeToken;
use App\Shared\Application\RealtimeTokens;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Centrifugo connection tokens (HS256, the key Centrifugo holds as `client.token.hmac_secret_key`). The `channels`
 * claim makes Centrifugo subscribe the connection server-side, so what a browser hears is decided here and nowhere
 * else: its user's channel and its working company's, in the two shapes the use cases publish to.
 */
final readonly class CentrifugoTokens implements RealtimeTokens
{
    public function __construct(
        private string $hmacKey,
        private ClockInterface $clock,
        private int $lifetimeSeconds,
    ) {
        if ($lifetimeSeconds <= 0) {
            throw new \InvalidArgumentException(\sprintf('A realtime token lifetime must be positive, %d given.', $lifetimeSeconds));
        }
    }

    public function issue(Uuid $userId, ?Uuid $workingCompanyId): RealtimeToken
    {
        $channels = ['user:'.$userId->toRfc4122()];
        if (null !== $workingCompanyId) {
            $channels[] = 'company:'.$workingCompanyId->toRfc4122();
        }

        return $this->issueFor($userId->toRfc4122(), $channels);
    }

    public function issueFor(string $subject, array $channels): RealtimeToken
    {
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', $this->lifetimeSeconds));

        return new RealtimeToken(
            HmacJwt::encode(['sub' => $subject, 'exp' => $expiresAt->getTimestamp(), 'channels' => $channels], $this->hmacKey),
            $expiresAt,
        );
    }
}
