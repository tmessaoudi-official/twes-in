<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** A module refused the merge, or its undo, for a reason of its own, which the screen says from its code. */
final class MergeRefused extends \RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(public readonly string $reason, string $message, public readonly array $params = [])
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $reason)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a reason code.', $reason));
        }
        parent::__construct($message);
    }
}
