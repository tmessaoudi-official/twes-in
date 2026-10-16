<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain\Model;

use App\Module\Invoices\Domain\Service\InvoiceChain;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Where a series' chain ends, written each time it is sealed further (`InvoiceChain`). The chain shows a document
 * changed, or taken from its middle, by itself; the last one taken away leaves a shorter chain whose every link holds,
 * which only this length and hash, kept apart from the documents, can show.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_chain_head')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_chain_head_series', columns: ['establishment_id', 'document_type'])]
#[ORM\Index(name: 'idx_invoice_chain_head_company', columns: ['company_id'])]
class InvoiceChainHead implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, onDelete: 'CASCADE')]
    private Establishment $establishment;

    #[ORM\Column(length: 16, enumType: InvoiceType::class)]
    private InvoiceType $documentType;

    #[ORM\Column(type: 'integer')]
    private int $position;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $hash;

    private function __construct(Company $company, Establishment $establishment, InvoiceType $documentType, int $position, string $hash)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->establishment = $establishment;
        $this->documentType = $documentType;
        $this->position = $position;
        $this->hash = $hash;
    }

    public static function at(Company $company, Establishment $establishment, InvoiceType $documentType, int $position, string $hash): self
    {
        return new self($company, $establishment, $documentType, $position, $hash);
    }

    public function moveTo(int $position, string $hash): void
    {
        if ($position < $this->position) {
            throw new \LogicException('A chain only grows.');
        }
        $this->position = $position;
        $this->hash = $hash;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
