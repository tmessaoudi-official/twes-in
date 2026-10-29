<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Shared\Application\RealtimeCuts;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Application\RealtimeSessions;
use App\Shared\Infrastructure\Doctrine\StagedUntilCommit;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds whose connections to close until the outermost unit of work commits, then closes each subject once: a
 * session's, or every session of an account, read then, with the account's own for a token asked with no session. A
 * cut asked outside such a unit, or in one rolled back, closes nothing.
 */
final class StagedRealtimeCuts implements RealtimeCuts, StagedUntilCommit, ResetInterface
{
    private int $depth = 0;

    /** @var array<string, Uuid> the accounts to close whole, by id */
    private array $accounts = [];

    /** @var array<string, true> the sessions' subjects to close */
    private array $sessions = [];

    public function __construct(private readonly RealtimePublisher $publisher, private readonly RealtimeSessions $recorded)
    {
    }

    public function cut(Uuid $userId): void
    {
        if (0 === $this->depth) {
            return;
        }
        $this->accounts[$userId->toRfc4122()] = $userId;
    }

    public function cutSession(Uuid $userId, Uuid $sessionId): void
    {
        if (0 === $this->depth) {
            return;
        }
        $this->sessions[RealtimeSubjects::of($userId, $sessionId)] = true;
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
        $subjects = [];
        // Read once the unit committed: the sessions an account holds then are those whose connections may be open.
        foreach ($this->accounts as $userId) {
            $subjects[RealtimeSubjects::of($userId, null)] = true;
            foreach ($this->recorded->of($userId) as $sessionId) {
                $subjects[RealtimeSubjects::of($userId, $sessionId)] = true;
            }
        }
        $subjects += $this->sessions;
        $this->accounts = [];
        $this->sessions = [];
        foreach (array_keys($subjects) as $subject) {
            $this->publisher->disconnect($subject);
        }
    }

    public function rollBack(): void
    {
        if (--$this->depth <= 0) {
            $this->depth = 0;
            $this->accounts = [];
            $this->sessions = [];
        }
    }

    public function reset(): void
    {
        $this->depth = 0;
        $this->accounts = [];
        $this->sessions = [];
    }
}
