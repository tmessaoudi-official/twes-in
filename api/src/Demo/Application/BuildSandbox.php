<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Demo\Domain\ValueObject\SandboxState;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The worker builds a queued sandbox. It is marked as being built before anything is written, so a message handed over
 * again (a retry, a restarted worker) finds it so and writes nothing; a build that stopped halfway stays as it is until
 * the removal of stale sandboxes takes its company and owner away.
 */
final readonly class BuildSandbox
{
    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private SandboxWriter $writer,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Uuid $sandboxId): void
    {
        $started = $this->transactions->run(function () use ($sandboxId): bool {
            $sandbox = $this->sandboxes->lockedOfId($sandboxId);
            if (null === $sandbox || SandboxState::Queued !== $sandbox->getState()) {
                return false;
            }
            $sandbox->startBuilding($this->clock->now());
            $this->sandboxes->save($sandbox);

            return true;
        });
        if (!$started) {
            return;
        }

        $this->writer->write($this->sandboxes->ofId($sandboxId) ?? throw new \LogicException('A sandbox being built vanished.'));

        $this->transactions->run(function () use ($sandboxId): void {
            $sandbox = $this->sandboxes->lockedOfId($sandboxId) ?? throw new \LogicException('A sandbox just built vanished.');
            $sandbox->ready($this->clock->now());
            $this->sandboxes->save($sandbox);
        });
    }
}
