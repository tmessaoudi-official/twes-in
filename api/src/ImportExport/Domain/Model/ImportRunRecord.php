<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Domain\Model;

use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row an import created, by its table and id, kept with the import so « Annuler cet import » knows what it made:
 * what it only changed is not here, and stays as it was changed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run_record')]
#[ORM\Index(name: 'idx_import_run_record_run', columns: ['run_id'])]
class ImportRunRecord implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private ImportRun $run;

    #[ORM\Column(length: 63)]
    private string $tableName;

    #[ORM\Column(type: 'uuid')]
    private Uuid $recordId;

    public function __construct(Company $company, ImportRun $run, string $tableName, Uuid $recordId)
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $tableName)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a table name.', $tableName));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->run = $run;
        $this->tableName = $tableName;
        $this->recordId = $recordId;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getRecordId(): Uuid
    {
        return $this->recordId;
    }
}
