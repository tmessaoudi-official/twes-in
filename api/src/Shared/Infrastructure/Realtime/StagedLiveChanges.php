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
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds what a unit of work changed until DoctrineTransactions commits its outermost level, then pushes each change
 * once to the company's channel, or to the actor's own when it belongs to no company. The depth is counted here rather
 * than read from the connection, whose transaction the test bundle keeps open around every test. The tab that made
 * the request names itself in an `X-Tab` header, echoed as `origin`, so it can tell its own change from another's.
 * Past ten changes of one kind and action by one person, as a file's import makes, they are said once as a bulk change
 * naming how many, so a screen reads its list again once; a deletion is always said record by record, since an open
 * editor must hear that its own record is gone.
 */
final class StagedLiveChanges implements LiveChanges, ResetInterface
{
    private const string TAB = '/^[A-Za-z0-9-]{1,64}$/';

    private const int ONE_BY_ONE = 10;

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
        $counts = [];
        foreach ($staged as $change) {
            $counts[$this->groupOf($change)] = ($counts[$this->groupOf($change)] ?? 0) + 1;
        }
        $said = [];
        $names = [];
        foreach ($staged as $change) {
            $group = $this->groupOf($change);
            if ($counts[$group] <= self::ONE_BY_ONE || str_ends_with($change->action, '.deleted')) {
                $this->publish($change, $names);
            } elseif (!isset($said[$group])) {
                $said[$group] = true;
                $this->publish($change, $names, $counts[$group]);
            }
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

    private function groupOf(LiveChange $change): string
    {
        return implode('|', [$change->companyId?->toRfc4122(), $change->actorUserId?->toRfc4122(), $change->kind, $change->action]);
    }

    /**
     * @param array<string, ?string> $names the actors' names read so far in this commit
     * @param ?int                   $count how many records a bulk change stands for, null for one record
     */
    private function publish(LiveChange $change, array &$names, ?int $count = null): void
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
            'id' => null === $count ? $change->id?->toRfc4122() : null,
            'action' => $change->action,
            'actor' => null === $change->actorUserId ? null : [
                'id' => $change->actorUserId->toRfc4122(),
                'name' => $this->nameOf($change->actorUserId, $names),
            ],
            'origin' => $this->origin(),
            ...(null === $count ? [] : ['bulk' => true, 'count' => $count]),
        ]);
    }

    /** @param array<string, ?string> $names */
    private function nameOf(Uuid $actor, array &$names): ?string
    {
        $key = $actor->toRfc4122();
        if (!\array_key_exists($key, $names)) {
            $names[$key] = $this->users->ofId($actor)?->getDisplayName();
        }

        return $names[$key];
    }

    private function origin(): ?string
    {
        $tab = $this->requests->getCurrentRequest()?->headers->get('X-Tab');

        return null !== $tab && 1 === preg_match(self::TAB, $tab) ? $tab : null;
    }
}
