<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application\Catalogue;

/**
 * One text a writer may word otherwise: the original shipped, the installation's wording and the company's (null where
 * none), the version of the writer's own level (0 for none), and whether the original changed since the writer's own
 * was written.
 */
final readonly class TextCatalogueEntry
{
    public function __construct(
        public string $domain,
        public string $key,
        public string $original,
        public ?string $installation,
        public ?string $company,
        public int $version,
        public bool $originalChanged,
    ) {
    }
}
