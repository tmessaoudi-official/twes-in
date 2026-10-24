<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application\Catalogue;

/** What a writer looks for: words a text says or a key, one file, only the texts whose original changed; one page of it. */
final readonly class TextCatalogueQuery
{
    public function __construct(
        public ?string $search,
        public ?string $domain,
        public bool $originalChanged,
        public int $page,
        public int $perPage,
    ) {
    }
}
