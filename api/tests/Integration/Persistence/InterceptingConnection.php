<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;

/**
 * A DBAL connection that runs a callback the first time a query containing a marker is read back.
 *
 * **WHY THIS EXISTS: it is the only way to get between `save()`'s pre-read and `save()`'s upsert inside ONE PHP
 * process,** and without it the write-once number predicate at `DoctrineInvoiceRepository:328` is pinned by
 * nothing. Round 6's correctness lens (F1) proved that: deleting
 * `(document.number IS NULL OR document.number = EXCLUDED.number)` left the whole 293-case integration suite
 * green, including `testADocumentNumberCannotBeRewrittenOnceAssigned`, which a docblock named as pinning it in
 * three places. That test never reaches the statement — by the time it attempts a rewrite the stored row already
 * carries a number, so `save()`'s pre-read sends it down the state-only `UPDATE` branch instead.
 *
 * The predicate exists for the CONCURRENT case: two writers, one document, the loser arriving with a fresh
 * number after the winner has committed. Reproducing that needs the rival to commit BETWEEN the victim's read
 * and the victim's write, which no straight-line case can express.
 *
 * **NOT a second process, and that is the point.** `InvoiceLifecycleTest` records that observing the row lock
 * "would take a second PHP process", and that is true of a lock — a relationship between two live sessions. It
 * is NOT true of this, because the interleaving needed here is only *ordering*, not *simultaneity*: the rival
 * does its whole job and commits while the victim is suspended inside a callback. `CLAUDE.md` § Gotchas
 * 2026-07-30 forbids recording a gap as an impossibility for exactly this reason — the same repository already
 * killed a *"would need PostgreSQL to lie"* claim with a nine-line `PDOStatement` subclass, and this is that
 * trick one layer up, at DBAL's own `wrapperClass` seam rather than PDO's.
 *
 * Deliberately NOT a driver middleware, though this repository has five of them: a middleware wraps every
 * connection the kernel builds and would need registering and unregistering around the case. `wrapperClass` is
 * DBAL's supported extension point for exactly this, is scoped to the one connection the case constructs, and
 * touches no production wiring at all.
 *
 * FIRES ONCE. A re-entrant callback would recurse through whatever the rival itself reads, and `save()` is free
 * to read more than once; latching means the case pins the interleaving it describes rather than an incidental
 * one.
 */
final class InterceptingConnection extends Connection
{
    /** @var callable():void|null */
    private $onRead = null;

    private string $marker = '';

    private bool $fired = false;

    /**
     * @param callable():void $callback
     */
    public function interceptOnce(string $marker, callable $callback): void
    {
        $this->marker = $marker;
        $this->onRead = $callback;
        $this->fired = false;
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, \Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type|int|string|null> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        // The value is read BEFORE the callback runs, so the caller receives the STALE answer — which is the whole
        // point. Reading after would hand it the rival's number and the upsert would never see a conflict.
        $value = parent::fetchOne($query, $params, $types);

        if (!$this->fired && null !== $this->onRead && '' !== $this->marker && str_contains($query, $this->marker)) {
            $this->fired = true;
            ($this->onRead)();
        }

        return $value;
    }

    public function hasFired(): bool
    {
        return $this->fired;
    }
}
