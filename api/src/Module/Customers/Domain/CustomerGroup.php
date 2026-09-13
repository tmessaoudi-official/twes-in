<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Customers a company treats alike (docs/SPEC.md § 4 customer_group). What they share is settings: the parties chain
 * reads a group's values between the company's and the customer's own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_group')]
#[ORM\Index(name: 'idx_customer_group_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_customer_group_company_name', columns: ['company_id', 'name'])]
class CustomerGroup
{
    public const int NAME_MAX = 120;
    public const int DESCRIPTION_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: self::DESCRIPTION_MAX, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidCustomerGroup */
    public static function create(Company $company, string $name, ?string $description, \DateTimeImmutable $now): self
    {
        $group = new self($company, $now);
        $group->name = self::name($name);
        $group->description = self::description($description);

        return $group;
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidCustomerGroup
     */
    public function revise(string $name, ?string $description, \DateTimeImmutable $now): bool
    {
        $next = [self::name($name), self::description($description)];
        if ($next === [$this->name, $this->description]) {
            return false;
        }
        [$this->name, $this->description] = $next;
        $this->updatedAt = $now;

        return true;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidCustomerGroup('name', \sprintf('A customer group is named in 1 to %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    private static function description(?string $description): ?string
    {
        $description = null === $description ? '' : trim($description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidCustomerGroup('description', \sprintf('A customer group is described in at most %d characters.', self::DESCRIPTION_MAX));
        }

        return '' === $description ? null : $description;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
