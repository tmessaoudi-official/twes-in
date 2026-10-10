<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row an erasure took, as it was, or one link it cut (a kept row's column that named a row taken). The copy holds
 * whatever the rows held, personal data included, and lives only as long as its erasure may be undone. It is written and
 * read in bulk by the erasure's own SQL; the mapping is here so the schema says what it is.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'data_erasure_row')]
#[ORM\Index(name: 'idx_data_erasure_row_erasure', columns: ['erasure_id', 'step'])]
class DataErasureRow implements CompanyOwned
{
    public const string ROW = 'row';
    public const string LINK = 'link';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DataErasure::class)]
    #[ORM\JoinColumn(name: 'erasure_id', nullable: false, onDelete: 'CASCADE')]
    private DataErasure $erasure;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 63)]
    private string $tableName;

    /** The order rows go back in: what they name first. */
    #[ORM\Column]
    private int $step;

    #[ORM\Column(length: 8)]
    private string $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $snapshot;

    /** @param array<string, mixed> $snapshot */
    public function __construct(DataErasure $erasure, string $tableName, int $step, string $kind, array $snapshot)
    {
        $this->id = Uuid::v7();
        $this->erasure = $erasure;
        $this->company = $erasure->getCompany();
        $this->tableName = $tableName;
        $this->step = $step;
        $this->kind = $kind;
        $this->snapshot = $snapshot;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getErasure(): DataErasure
    {
        return $this->erasure;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }
}
