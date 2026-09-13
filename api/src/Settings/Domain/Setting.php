<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One value stored at one level for one key. A key without a row at a level inherits from the level above. */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
#[ORM\UniqueConstraint(name: 'uniq_setting_level_key', columns: ['level', 'level_id', 'key'])]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 24, enumType: SettingLevel::class)]
    private SettingLevel $level;

    #[ORM\Column(name: 'level_id', length: 80)]
    private string $levelId;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\Column(length: 160)]
    private string $key;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private mixed $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SettingAddress $address, string $key, mixed $value, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->level = $address->level;
        $this->levelId = $address->levelId;
        $this->company = $address->company;
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLevel(): SettingLevel
    {
        return $this->level;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function isAt(SettingAddress $address): bool
    {
        return $this->level === $address->level && $this->levelId === $address->levelId;
    }

    /** @return bool whether anything changed */
    public function change(mixed $value, \DateTimeImmutable $now): bool
    {
        if ($value === $this->value) {
            return false;
        }
        $this->value = $value;
        $this->updatedAt = $now;

        return true;
    }
}
