<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A list holding at most the entries `ListLimits` gives its name. `Count(max: …)` takes a number written in the code;
 * this one reads the operator's parameter.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ListLimit extends Constraint
{
    public const string TOO_MANY = '6a1d9e44-2f0b-4c3e-8d57-91b0c4e7a2f3';

    protected const ERROR_NAMES = [self::TOO_MANY => 'TOO_MANY'];

    public string $message = 'This list holds at most {{ limit }} entries.';

    public function __construct(public readonly string $list, ?array $groups = null)
    {
        parent::__construct(null, $groups, ['reason' => 'too_many_entries']);
    }
}
