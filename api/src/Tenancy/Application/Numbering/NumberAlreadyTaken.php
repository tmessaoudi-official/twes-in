<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/**
 * The number a series gave is already on another document of the company: two establishments' series write the same
 * numbers, or a series was set back. Each document that takes a number has its own refusal extending this one, which
 * names the number, the establishment and the document type, so the screen says which series to change.
 */
abstract class NumberAlreadyTaken extends \RuntimeException
{
    public function __construct(string $message, public readonly string $number, public readonly string $establishment, public readonly string $documentType)
    {
        parent::__construct($message);
    }
}
