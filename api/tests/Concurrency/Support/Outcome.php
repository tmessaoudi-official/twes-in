<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

/**
 * How one racer ended. A deadlock (40P01) and a lock wait past the harness's timeout (55P03) are failures of their own
 * kind, never an ordinary refusal: under contention the code must order its locks, not leave PostgreSQL to break the tie.
 */
final readonly class Outcome
{
    public const string OK = 'ok';
    public const string DEADLOCK = 'deadlock';
    public const string LOCK_TIMEOUT = 'lock_timeout';
    public const string ERROR = 'error';
    public const string CRASHED = 'crashed';

    public function __construct(
        public int $index,
        public string $kind,
        public mixed $value = null,
        public ?string $sqlState = null,
        public string $message = '',
    ) {
    }

    /** The kind a failure's SQLSTATE says, an ordinary error otherwise. */
    public static function kindOf(?string $sqlState): string
    {
        return match ($sqlState) {
            '40P01' => self::DEADLOCK,
            '55P03' => self::LOCK_TIMEOUT,
            default => self::ERROR,
        };
    }

    public function describe(): string
    {
        return \sprintf('racer %d: %s%s%s', $this->index, $this->kind, null === $this->sqlState ? '' : ' '.$this->sqlState, '' === $this->message ? '' : ' — '.$this->message);
    }
}
