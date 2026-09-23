<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\RealtimePublisher;

final class RecordingRealtimePublisher implements RealtimePublisher
{
    /** @var list<array{channel: string, data: array<string, mixed>}> */
    public array $pushed = [];

    /** Runs at the moment of the push, so a test can look at what had already been written by then. */
    public ?\Closure $onPush = null;

    /** @return array{channel: string, data: array<string, mixed>} */
    public function last(): array
    {
        return $this->pushed[\count($this->pushed) - 1] ?? throw new \LogicException('Nothing was pushed.');
    }

    public function push(string $channel, array $data): void
    {
        if (null !== $this->onPush) {
            ($this->onPush)();
        }
        $this->pushed[] = ['channel' => $channel, 'data' => $data];
    }
}
