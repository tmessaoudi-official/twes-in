<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Where a company's generated product references stand: one counter, whichever format a category writes it with, so
 * two formats never hand out one number twice. A row rather than a setting, because taking a number locks it in the
 * transaction that stores the product. Nothing here is fiscal: a number a refused product leaves behind is not
 * reused, and nobody needs it to be.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_reference_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_product_reference_sequence_company', columns: ['company_id'])]
class ProductReferenceSequence implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $nextNumber = 1;

    public function __construct(Company $company)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
    }

    /** The number the next generated reference is tried with. */
    public function nextNumber(): int
    {
        return $this->nextNumber;
    }

    /** The number tried now, and the sequence moved past it whether it is kept or found taken. */
    public function take(): int
    {
        return $this->nextNumber++;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
