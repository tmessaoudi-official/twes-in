<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use Symfony\Component\Validator\Constraint;

/** A tag of the language catalogue that documents print in, written as the catalogue writes it. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DocumentLanguage extends Constraint
{
    public const string NOT_PRINTED_IN = 'b3f1c0a2-5d4e-4a8b-9c71-2e6f0d8a4b15';

    protected const ERROR_NAMES = [self::NOT_PRINTED_IN => 'NOT_PRINTED_IN'];

    public string $message = 'Documents are not printed in {{ value }}.';

    public function __construct(?array $groups = null)
    {
        parent::__construct(null, $groups);
    }
}
