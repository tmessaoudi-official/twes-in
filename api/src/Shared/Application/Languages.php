<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The languages twes-in knows, as data (`config/languages.json`, which the web carries an identical copy of): adding
 * one is an entry there, its texts and, for a new script, a font, never a code change. Every tag is a canonical BCP 47
 * tag, as stored.
 */
interface Languages
{
    /** The language anything unspoken falls back to; always one the interface speaks. */
    public function default(): string;

    /** @return list<string> the languages whose screen texts and mails ship, the default first */
    public function interface(): array;

    /** @return list<string> the languages a document may be printed in */
    public function documents(): array;

    /** @return list<string> the languages the legal pages are written in */
    public function legal(): array;

    /** The tag if the interface speaks it, else the default. */
    public function interfaceOr(?string $asked): string;
}
