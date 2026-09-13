<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A unit a company sells in. The code is a UN/ECE Recommendation 20 code, which an EN 16931 invoice line carries
 * (BT-130), so it is fixed once created; the name is the company's own wording.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unit')]
#[ORM\UniqueConstraint(name: 'uniq_unit_company_code', columns: ['company_id', 'code'])]
class Unit
{
    public const string CODE = '/^[A-Z0-9]{2,3}$/';
    /** A quantity is NUMERIC(14,3). */
    public const int MAX_DECIMALS = 3;
    private const int NAME_MAX = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 3)]
    private string $code;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $decimals = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, string $code, \DateTimeImmutable $now)
    {
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidFiscalValue('code', \sprintf('"%s" is not a UN/ECE Recommendation 20 code: two or three capital letters or digits.', $code));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->code = $code;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function create(Company $company, string $code, string $name, int $decimals, int $sortOrder, \DateTimeImmutable $now): self
    {
        $unit = new self($company, $code, $now);
        $unit->apply($name, $decimals, true, $sortOrder);

        return $unit;
    }

    /** @return bool whether anything changed */
    public function revise(string $name, int $decimals, bool $isActive, int $sortOrder, \DateTimeImmutable $now): bool
    {
        $before = [$this->name, $this->decimals, $this->isActive, $this->sortOrder];
        $this->apply($name, $decimals, $isActive, $sortOrder);
        if ($before === [$this->name, $this->decimals, $this->isActive, $this->sortOrder]) {
            return false;
        }
        $this->updatedAt = $now;

        return true;
    }

    private function apply(string $name, int $decimals, bool $isActive, int $sortOrder): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidFiscalValue('name', \sprintf('A unit name has between 1 and %d characters.', self::NAME_MAX));
        }
        if ($decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new InvalidFiscalValue('decimals', \sprintf('A quantity carries between 0 and %d decimals.', self::MAX_DECIMALS));
        }
        if ($sortOrder < 0) {
            throw new InvalidFiscalValue('sortOrder', 'A sort order is never negative.');
        }
        $this->name = $name;
        $this->decimals = $decimals;
        $this->isActive = $isActive;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
