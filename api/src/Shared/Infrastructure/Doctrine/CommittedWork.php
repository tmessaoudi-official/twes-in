<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether this request has already committed a unit of work. A failure after one is not « nothing was kept »: the
 * request must not be sent again as if it had done nothing (`BusyListener`), since what committed would be made twice.
 */
final class CommittedWork implements StagedUntilCommit, ResetInterface
{
    private int $depth = 0;

    private bool $any = false;

    public function begin(): void
    {
        ++$this->depth;
    }

    public function commit(): void
    {
        if (0 === --$this->depth) {
            $this->any = true;
        }
    }

    public function rollBack(): void
    {
        --$this->depth;
    }

    /** Whether an outermost unit of work of this request committed. */
    public function any(): bool
    {
        return $this->any;
    }

    public function reset(): void
    {
        $this->depth = 0;
        $this->any = false;
    }
}
