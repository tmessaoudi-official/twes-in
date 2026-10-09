<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** One switch of an import file as the guide describes it, for the screen to show beside the mode. */
final readonly class ImportGuideSwitch
{
    public function __construct(
        #[Groups([ImportGuideResource::READ])]
        public string $key,
        #[Groups([ImportGuideResource::READ])]
        public string $labelKey,
        #[Groups([ImportGuideResource::READ])]
        public ?string $noteKey,
    ) {
    }
}
