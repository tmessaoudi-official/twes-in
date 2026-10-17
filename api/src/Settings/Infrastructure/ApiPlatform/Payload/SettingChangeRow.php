<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\Payload;

use ApiPlatform\Metadata\ApiProperty;
use App\Settings\Application\SettingChange;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingChangesResource;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingResource;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** One key of a save, embedded in `SettingChangesResource`, never a resource of its own. */
final class SettingChangeRow
{
    #[Assert\NotBlank(groups: [SettingChangesResource::WRITE])]
    #[Assert\Regex('/^'.SettingResource::KEY.'$/', groups: [SettingChangesResource::WRITE])]
    #[Groups([SettingChangesResource::WRITE])]
    public string $key = '';

    #[ApiProperty(schema: SettingResource::ANY_VALUE)]
    #[Groups([SettingChangesResource::WRITE])]
    public mixed $value = null;

    /** The version the key's row held at the level when read (`versions` of GET .../settings), 0 when it held none. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\PositiveOrZero(groups: [RecordVersion::REVISE])]
    #[Groups([RecordVersion::REVISE])]
    public ?int $version = null;

    public function change(): SettingChange
    {
        return new SettingChange($this->key, $this->value, $this->version ?? throw new \LogicException('A change is validated with its version.'));
    }
}
