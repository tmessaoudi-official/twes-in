<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Shared\Domain\ValueObject\Refusal;

/**
 * Values a save would not store, each under its setting's key: the reason a screen says it in and that text's
 * parameters, every key refused at once, so a page marks each field rather than one per attempt.
 */
final class InvalidSettingValue extends \RuntimeException
{
    /** @param non-empty-array<string, Refusal> $refusals by setting key, in the order the save named them */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(implode(' ', array_map(static fn (string $key, Refusal $refusal): string => \sprintf('%s: %s.', $key, $refusal->message), array_keys($refusals), $refusals)));
    }
}
