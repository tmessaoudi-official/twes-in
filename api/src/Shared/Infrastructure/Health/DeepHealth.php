<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Judges the readings against their limits and names every check that fails, so an alert says what broke rather than
 * that something did.
 */
final readonly class DeepHealth
{
    public function __construct(
        #[Autowire('%app.health.oldest_waiting_seconds%')] private int $oldestWaitingLimit,
        #[Autowire('%app.health.disk_free_minimum%')] private float $diskFreeMinimum,
    ) {
    }

    /** @return array{ok: bool, failing: list<string>, readings: array<string, mixed>} */
    public function judge(HealthReadings $readings): array
    {
        $failing = [];
        if ($readings->failedMessages > 0) {
            $failing[] = 'failedMessages';
        }
        if (null !== $readings->oldestWaitingSeconds && $readings->oldestWaitingSeconds > $this->oldestWaitingLimit) {
            $failing[] = 'oldestWaiting';
        }
        if ($readings->diskFree < $this->diskFreeMinimum) {
            $failing[] = 'diskFree';
        }
        if (!\in_array($readings->archiving, [Archiving::NotExpected, Archiving::Working], true)) {
            $failing[] = 'archiving';
        }

        return [
            'ok' => [] === $failing,
            'failing' => $failing,
            'readings' => [
                'failedMessages' => $readings->failedMessages,
                'oldestWaitingSeconds' => $readings->oldestWaitingSeconds,
                'diskFree' => $readings->diskFree,
                'archiving' => $readings->archiving->value,
            ],
        ];
    }
}
