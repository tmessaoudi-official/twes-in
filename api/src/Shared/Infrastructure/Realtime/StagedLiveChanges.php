<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Identity\Domain\UserRepository;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\RealtimePublisher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds what a unit of work changed until DoctrineTransactions commits its outermost level, then pushes each change
 * once to the company's channel, or to the actor's own when it belongs to no company. The depth is counted here rather
 * than read from the connection, whose transaction the test bundle keeps open around every test. The tab that made
 * the request names itself in an `X-Tab` header, echoed as `origin`, so it can tell its own change from another's.
 */
final class StagedLiveChanges implements LiveChanges, ResetInterface
{
    private const string TAB = '/^[A-Za-z0-9-]{1,64}$/';

    private int $depth = 0;

    /** @var array<string, LiveChange> */
    private array $staged = [];

    public function __construct(
        private readonly RealtimePublisher $publisher,
        private readonly UserRepository $users,
        private readonly RequestStack $requests,
    ) {
    }

    public function stage(LiveChange $change): void
    {
        if (0 === $this->depth) {
            return;
        }
        $key = implode('|', [$change->companyId?->toRfc4122(), $change->actorUserId?->toRfc4122(), $change->kind, $change->id?->toRfc4122(), $change->action]);
        $this->staged[$key] ??= $change;
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
        $staged = $this->staged;
        $this->staged = [];
        foreach ($staged as $change) {
            $this->publish($change);
        }
    }

    public function rollBack(): void
    {
        if (--$this->depth <= 0) {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->depth = 0;
        $this->staged = [];
    }

    private function publish(LiveChange $change): void
    {
        $channel = match (true) {
            null !== $change->companyId => 'company:'.$change->companyId->toRfc4122(),
            null !== $change->actorUserId => 'user:'.$change->actorUserId->toRfc4122(),
            default => null,
        };
        if (null === $channel) {
            return;
        }
        $this->publisher->push($channel, [
            'type' => 'changed',
            'kind' => $change->kind,
            'id' => $change->id?->toRfc4122(),
            'action' => $change->action,
            'actor' => null === $change->actorUserId ? null : [
                'id' => $change->actorUserId->toRfc4122(),
                'name' => $this->users->ofId($change->actorUserId)?->getDisplayName(),
            ],
            'origin' => $this->origin(),
        ]);
    }

    private function origin(): ?string
    {
        $tab = $this->requests->getCurrentRequest()?->headers->get('X-Tab');

        return null !== $tab && 1 === preg_match(self::TAB, $tab) ? $tab : null;
    }
}
