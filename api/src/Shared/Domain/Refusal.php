<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** A value refused: the stable reason a screen translates, that text's parameters, and the English message beside them. */
final readonly class Refusal
{
    /** @param array<string, string|int> $params */
    public function __construct(public string $reason, public string $message, public array $params = [])
    {
    }
}
