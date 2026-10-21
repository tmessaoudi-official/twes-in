<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Effects;

use App\Shared\Application\Effect;
use App\Shared\Application\Effects;
use App\Shared\Infrastructure\Doctrine\StagedUntilCommit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps what a request's units of work said until each commits, for the request's answer (`EffectsAnswer`). Each level
 * of a nested unit keeps its own, so an inner unit rolled back to its savepoint takes back only what it said while the
 * outer one commits. One kernel serves many requests in worker mode, so what a request said is forgotten after it.
 */
final class StagedEffects implements Effects, StagedUntilCommit, ResetInterface
{
    /** @var list<list<Effect>> what each open level said, the innermost last */
    private array $levels = [];

    /** @var list<Effect> */
    private array $kept = [];

    public function said(Effect $effect): void
    {
        if ([] === $this->levels) {
            $this->kept[] = $effect;

            return;
        }
        $this->levels[\count($this->levels) - 1][] = $effect;
    }

    public function begin(): void
    {
        $this->levels[] = [];
    }

    public function commit(): void
    {
        $level = array_pop($this->levels) ?? [];
        if ([] === $this->levels) {
            $this->kept = [...$this->kept, ...$level];

            return;
        }
        $outer = \count($this->levels) - 1;
        $this->levels[$outer] = [...$this->levels[$outer], ...$level];
    }

    public function rollBack(): void
    {
        array_pop($this->levels);
    }

    public function reset(): void
    {
        $this->levels = [];
        $this->kept = [];
    }

    /**
     * What this request's committed units said, in the order they said it.
     *
     * @return list<Effect>
     */
    public function saidHere(): array
    {
        return $this->kept;
    }
}
