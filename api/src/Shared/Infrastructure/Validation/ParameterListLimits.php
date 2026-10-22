<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use App\Shared\Application\ListLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The limits as `app.list_limits` writes them, each checked at start. */
final readonly class ParameterListLimits implements ListLimits
{
    /** @param array<string, int> $limits */
    public function __construct(#[Autowire(param: 'app.list_limits')] private array $limits)
    {
        foreach ($limits as $list => $most) {
            if ($most < 1) {
                throw new \LogicException(\sprintf('app.list_limits gives %s a limit of %d: a list holds at least one entry.', $list, $most));
            }
        }
    }

    public function most(string $list): int
    {
        return $this->limits[$list] ?? throw new \LogicException(\sprintf('app.list_limits names no limit for %s.', $list));
    }
}
