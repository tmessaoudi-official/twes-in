<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\Model;

use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row a delete took, as it was. The copy holds whatever the row held, a vendor's bank details included, and lives
 * only as long as its delete may be undone. It is written and read in bulk by the records engine's own SQL; the mapping
 * is here so the schema says what it is.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'record_removal_row')]
#[ORM\Index(name: 'idx_record_removal_row_removal', columns: ['removal_id', 'step'])]
class RecordRemovalRow implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RecordRemoval::class)]
    #[ORM\JoinColumn(name: 'removal_id', nullable: false, onDelete: 'CASCADE')]
    private RecordRemoval $removal;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 63)]
    private string $tableName;

    /** The order rows go back in: what they name first. */
    #[ORM\Column]
    private int $step;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $snapshot;

    /** @param array<string, mixed> $snapshot */
    public function __construct(RecordRemoval $removal, string $tableName, int $step, array $snapshot)
    {
        $this->id = Uuid::v7();
        $this->removal = $removal;
        $this->company = $removal->getCompany();
        $this->tableName = $tableName;
        $this->step = $step;
        $this->snapshot = $snapshot;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRemoval(): RecordRemoval
    {
        return $this->removal;
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

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }
}
