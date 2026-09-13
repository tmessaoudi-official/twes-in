<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Whom a read or a change is for: the company, the role the person holds in it, the person. A level whose subject
 * the context lacks is skipped when reading and refused when writing. A customer carries its group, so reading as
 * a customer walks the group's defaults too; products and documents add their subject when they arrive.
 */
final readonly class SettingContext
{
    public function __construct(
        public ?Company $company = null,
        public ?Uuid $roleId = null,
        public ?Uuid $userId = null,
        public ?Uuid $customerGroupId = null,
        public ?Uuid $customerId = null,
    ) {
    }

    public function addressOf(SettingLevel $level): ?SettingAddress
    {
        return match ($level) {
            SettingLevel::Platform => SettingAddress::platform(),
            SettingLevel::Company => null === $this->company ? null : SettingAddress::company($this->company),
            SettingLevel::Role => null === $this->company || null === $this->roleId ? null : SettingAddress::role($this->company, $this->roleId),
            SettingLevel::CustomerGroup => null === $this->company || null === $this->customerGroupId ? null : SettingAddress::customerGroup($this->company, $this->customerGroupId),
            SettingLevel::Customer => null === $this->company || null === $this->customerId ? null : SettingAddress::customer($this->company, $this->customerId),
            SettingLevel::User => null === $this->userId ? null : SettingAddress::user($this->userId),
            default => null,
        };
    }

    /** @return list<SettingAddress> the addresses of the chain this context reaches, most general first */
    public function addresses(SettingChain $chain): array
    {
        $addresses = [];
        foreach ($chain->levels() as $level) {
            $address = $this->addressOf($level);
            if (null !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
