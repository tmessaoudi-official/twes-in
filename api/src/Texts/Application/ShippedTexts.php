<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Texts\Domain\ValueObject\TextAddress;

/**
 * The texts a release ships, which an override lays a company's or the installation's words over: the screens' files
 * and the domains the API writes its own texts in.
 */
interface ShippedTexts
{
    /** The text at that address, or null when no text is there (a group of texts, an unknown file or language). */
    public function original(TextAddress $address): ?string;

    /** @return list<string> `screen`, each `screen:<part>`, then the API's domains */
    public function domains(): array;

    /** @return array<string, string> every text of a file in a language, by its dotted key */
    public function texts(string $language, string $domain): array;
}
