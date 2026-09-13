<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Where a value is stored: a level and its subject, written as the row's `level_id`. Nothing for the platform;
 * the company for a company; the company and the role for a role, because the built-in roles are shared by every
 * company; the user for a user, whose own preferences follow them from one company to the next. The company is
 * kept for every level inside one, so its settings go when it goes.
 */
final readonly class SettingAddress
{
    private function __construct(public SettingLevel $level, public string $levelId, public ?Company $company)
    {
    }

    public static function platform(): self
    {
        return new self(SettingLevel::Platform, '', null);
    }

    public static function company(Company $company): self
    {
        return new self(SettingLevel::Company, $company->getId()->toRfc4122(), $company);
    }

    public static function role(Company $company, Uuid $roleId): self
    {
        return new self(SettingLevel::Role, $company->getId()->toRfc4122().'/'.$roleId->toRfc4122(), $company);
    }

    public static function user(Uuid $userId): self
    {
        return new self(SettingLevel::User, $userId->toRfc4122(), null);
    }
}
