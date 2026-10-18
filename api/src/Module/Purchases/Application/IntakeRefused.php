<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

/**
 * The stock refused a receipt: which line, by its index (`$index`), or the receipt itself (null), which field, and why,
 * in the stock's own words.
 */
final class IntakeRefused extends \DomainException
{
    public function __construct(public readonly ?int $index, public readonly string $field, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
