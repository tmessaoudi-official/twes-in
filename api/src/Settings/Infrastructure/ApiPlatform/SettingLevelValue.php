<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** The value one level holds for a setting. */
final class SettingLevelValue
{
    public function __construct(
        #[Groups([SettingResource::READ])]
        public string $level = '',
        #[Groups([SettingResource::READ])]
        public mixed $value = null,
    ) {
    }
}
