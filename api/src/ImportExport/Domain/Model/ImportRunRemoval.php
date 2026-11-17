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
 * One row an import removed, by its table and id, with what « Annuler cet import » needs to put it back: a file that
 * replaces a list (who a product is bought from) drops the rows it no longer names, and its undo brings them back.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run_removal')]
#[ORM\Index(name: 'idx_import_run_removal_run', columns: ['run_id'])]
class ImportRunRemoval implements CompanyOwned
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

    /** @var array<string, mixed> what puts the row back, as the subject that removed it wrote it */
    #[ORM\Column(type: 'json')]
    private array $what;

    /** @param array<string, mixed> $what */
    public function __construct(Company $company, ImportRun $run, string $tableName, Uuid $recordId, array $what)
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $tableName)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a table name.', $tableName));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->run = $run;
        $this->tableName = $tableName;
        $this->recordId = $recordId;
        $this->what = $what;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
