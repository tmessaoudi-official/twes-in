<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\Model;

use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row a merge pointed at the record kept, and what it named before, so the merge's undo points it back while it
 * still names the record kept. It is written and read in bulk by the records engine's own SQL; the mapping is here so
 * the schema says what it is.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'record_merge_move')]
#[ORM\Index(name: 'idx_record_merge_move_removal', columns: ['removal_id', 'table_name', 'column_name'])]
class RecordMergeMove implements CompanyOwned
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

    #[ORM\Column(length: 63)]
    private string $columnName;

    /** The moved row's own id. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $rowId;

    /** What the column held before the merge, as text: an id, or a company path; nothing for an alias's first link. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromValue;

    /** What the merge wrote: the undo points the row back only while it still holds it. */
    #[ORM\Column(length: 255)]
    private string $toValue;

    public function __construct(RecordRemoval $removal, string $tableName, string $columnName, Uuid $rowId, ?string $fromValue, string $toValue)
    {
        $this->id = Uuid::v7();
        $this->removal = $removal;
        $this->company = $removal->getCompany();
        $this->tableName = $tableName;
        $this->columnName = $columnName;
        $this->rowId = $rowId;
        $this->fromValue = $fromValue;
        $this->toValue = $toValue;
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

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getRowId(): Uuid
    {
        return $this->rowId;
    }

    public function getFromValue(): ?string
    {
        return $this->fromValue;
    }

    public function getToValue(): string
    {
        return $this->toValue;
    }
}
