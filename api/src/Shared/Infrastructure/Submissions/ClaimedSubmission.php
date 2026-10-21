<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use App\Shared\Infrastructure\Doctrine\StagedUntilCommit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The key this request claimed, marked done inside the work's outermost transaction: the claim then says the work was
 * made exactly when the work is, so a request stopped between its commit and its answer is never made again, and one
 * stopped before its commit leaves a claim the next attempt takes back. One kernel serves many requests in worker mode,
 * so what a request claimed is forgotten after it.
 */
final class ClaimedSubmission implements StagedUntilCommit, ResetInterface
{
    private ?HeldClaim $held = null;

    private int $depth = 0;

    private bool $made = false;

    public function __construct(private readonly SubmissionClaims $claims)
    {
    }

    public function hold(HeldClaim $claim): void
    {
        $this->held = $claim;
    }

    public function begin(): void
    {
        if (0 === $this->depth++ && null !== $this->held && !$this->made) {
            $this->claims->markDone($this->held);
        }
    }

    public function commit(): void
    {
        if (0 === --$this->depth && null !== $this->held) {
            $this->made = true;
        }
    }

    public function rollBack(): void
    {
        --$this->depth;
    }

    /** Whether a unit of work of this request committed while it held its key. */
    public function made(): bool
    {
        return $this->made;
    }

    public function reset(): void
    {
        $this->held = null;
        $this->depth = 0;
        $this->made = false;
    }
}
