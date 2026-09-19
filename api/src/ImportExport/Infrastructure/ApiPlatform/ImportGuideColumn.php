<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** One column of an import file as the guide describes it: exactly one of `headingKey` and `label` is set. */
final readonly class ImportGuideColumn
{
    public function __construct(
        #[Groups([ImportGuideResource::READ])]
        public string $key,
        #[Groups([ImportGuideResource::READ])]
        public bool $required,
        #[Groups([ImportGuideResource::READ])]
        public ?string $headingKey,
        #[Groups([ImportGuideResource::READ])]
        public ?string $label,
        #[Groups([ImportGuideResource::READ])]
        public ?string $example,
        #[Groups([ImportGuideResource::READ])]
        public ?string $noteKey,
    ) {
    }
}
