<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy\Exception;

/**
 * Release-time cleanup failed, so this connection must be thrown away rather than returned to the pool.
 *
 * **A distinct type, because the two outcomes need different handling and a pool cannot tell them apart from a
 * driver message.** `discardSessionState()` normally leaves a connection clean enough to reuse. When the
 * rollback or the `DISCARD ALL` itself fails — most often because the backend is already gone — the connection
 * is in an unknown state and reusing it would carry whatever session state survived to the next tenant.
 *
 * Round 13 found the previous behaviour letting the raw `PDOException` escape, which in a `finally`-shaped
 * release path REPLACES the in-flight business exception: the caller loses the failure that caused the release
 * and sees a driver message instead. That is the masked-failure shape CLAUDE.md § Gotchas records four times.
 * Carrying the original as `$previous` keeps it, and naming the required action in the type keeps the caller
 * from having to guess.
 *
 * Note what this is NOT: a swallow. Cleanup failing is a real problem and this still throws — it just throws
 * something a pool can act on.
 */
final class ConnectionMustBeEvicted extends \RuntimeException
{
    public static function becauseCleanupFailed(\PDOException $cause): self
    {
        return new self(
            'Release-time cleanup failed, so this connection must be EVICTED rather than returned to the pool: '
            . $cause->getMessage()
            . ' — a connection whose rollback or DISCARD ALL failed is in an unknown state, and reusing it '
            . 'would carry whatever session state survived to the next tenant. Note a broken connection '
            . 'reports inTransaction() as TRUE, because pdo_pgsql reads PQtransactionStatus() and a dead '
            . 'backend answers PQTRANS_UNKNOWN.',
            0,
            $cause,
        );
    }
}
