<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** The mentions a document prints, as translation keys, and what fills each one's placeholders. */
final readonly class PrintedMentions
{
    /**
     * @param list<string>                         $keys
     * @param array<string, array<string, string>> $parameters by key, each placeholder's name (without its percent signs) and value
     */
    public function __construct(public array $keys, public array $parameters = [])
    {
    }
}
