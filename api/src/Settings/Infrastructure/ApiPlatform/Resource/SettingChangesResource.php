<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Settings\Application\SettingChange;
use App\Settings\Infrastructure\ApiPlatform\Payload\SettingChangeRow;
use App\Settings\Infrastructure\ApiPlatform\State\Processor\ChangePlatformSettingsProcessor;
use App\Settings\Infrastructure\ApiPlatform\State\Processor\ChangeSettingsProcessor;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A settings page's save: every key it changed at one level, each with the version
 * its row held when read, stored together or not at all. A key saved, created or reset since answers 409 for the whole
 * save, an unknown key 404 and a value refused 422; the page reads the settings again after a 204.
 */
#[ApiResource(
    shortName: 'SettingChanges',
    operations: [
        new Put(
            uriTemplate: '/companies/{companyId}/settings',
            processor: ChangeSettingsProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: false,
            denormalizationContext: ['groups' => [self::WRITE, self::COMPANY, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, self::COMPANY, RecordVersion::REVISE]],
        ),
        new Put(
            uriTemplate: '/platform/settings',
            name: 'platform_settings_change',
            processor: ChangePlatformSettingsProcessor::class,
            security: 'is_granted("platform.settings")',
            read: false,
            output: false,
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
    ],
)]
final class SettingChangesResource
{
    public const string WRITE = 'setting_changes:write';
    /** What names the level of a company's save; the platform has only one. */
    public const string COMPANY = 'setting_changes:company';
    private const int MAX = 200;

    /** @var list<SettingChangeRow> */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, max: self::MAX, groups: [self::WRITE])]
    #[Assert\Valid(groups: [self::WRITE, RecordVersion::REVISE])]
    #[Groups([self::WRITE])]
    public array $changes = [];

    #[Assert\NotBlank(groups: [self::COMPANY])]
    #[Groups([self::COMPANY])]
    public string $level = '';

    /** At the role level, the role whose default this is. */
    #[Groups([self::COMPANY])]
    public ?string $roleId = null;

    /** At the customer level, the customer whose own values these are. */
    #[Groups([self::COMPANY])]
    public ?string $customerId = null;

    /** At the customer group level, the group whose defaults these are. */
    #[Groups([self::COMPANY])]
    public ?string $customerGroupId = null;

    /** At the product level, the product whose own values these are. */
    #[Groups([self::COMPANY])]
    public ?string $productId = null;

    /** At the product category level, the category whose defaults these are. */
    #[Groups([self::COMPANY])]
    public ?string $productCategoryId = null;

    /** At the establishment level, the establishment whose own values these are. */
    #[Groups([self::COMPANY])]
    public ?string $establishmentId = null;

    /** @return list<SettingChange> */
    public function input(): array
    {
        return array_map(static fn (SettingChangeRow $row): SettingChange => $row->change(), $this->changes);
    }
}
