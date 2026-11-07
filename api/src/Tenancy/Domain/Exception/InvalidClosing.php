<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Exception;

/** A closing the books refuse: a day not before today, or one before the day already closed. Its message names the field. */
final class InvalidClosing extends \DomainException
{
    /**
     * @param string $reason `on_or_before` (today is still being worked) or `on_or_after` (a closed period never opens)
     * @param string $day    the day the closing may go to at the latest, or must reach at the least, YYYY-MM-DD
     */
    public function __construct(string $message, public readonly string $reason, public readonly string $day)
    {
        parent::__construct($message);
    }
}
