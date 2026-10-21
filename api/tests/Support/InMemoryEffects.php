<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Effect;
use App\Shared\Application\Effects;

/** Every effect said, in order; the unit of work that keeps or drops them is `StagedEffects`, tested on its own. */
final class InMemoryEffects implements Effects
{
    /** @var list<Effect> */
    public array $said = [];

    public function said(Effect $effect): void
    {
        $this->said[] = $effect;
    }

    /** @return list<array{string, ?string, array<string, string|int|bool|null>}> */
    public function told(): array
    {
        return array_map(static fn (Effect $effect): array => [$effect->kind, $effect->id?->toRfc4122(), $effect->params], $this->said);
    }
}
