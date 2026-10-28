<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Shared\Application\RealtimeCuts;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Infrastructure\Doctrine\StagedUntilCommit;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds whose connections to close until the outermost unit of work commits, then closes each person's once; a cut
 * asked outside such a unit, or in one rolled back, closes nothing.
 */
final class StagedRealtimeCuts implements RealtimeCuts, StagedUntilCommit, ResetInterface
{
    private int $depth = 0;

    /** @var array<string, true> */
    private array $staged = [];

    public function __construct(private readonly RealtimePublisher $publisher)
    {
    }

    public function cut(Uuid $userId): void
    {
        if (0 === $this->depth) {
            return;
        }
        $this->staged[$userId->toRfc4122()] = true;
    }

    public function begin(): void
    {
        ++$this->depth;
    }

    public function commit(): void
    {
        if (--$this->depth > 0) {
            return;
        }
        $this->depth = 0;
        $staged = array_keys($this->staged);
        $this->staged = [];
        foreach ($staged as $user) {
            $this->publisher->disconnect($user);
        }
    }

    public function rollBack(): void
    {
        if (--$this->depth <= 0) {
            $this->depth = 0;
            $this->staged = [];
        }
    }

    public function reset(): void
    {
        $this->depth = 0;
        $this->staged = [];
    }
}
