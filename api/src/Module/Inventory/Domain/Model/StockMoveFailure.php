<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain\Model;

use App\Module\Inventory\Domain\ValueObject\FailedMove;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A document's stock move that failed after the document was kept: the note stays validated, the invoice issued, and
 * its goods did not move (workflow hunt, CON-1). Kept until someone holding `stock.write` moves it again from the
 * document's page or says it is settled, so the stock keepers act on it there rather than wait for a console on the
 * shop's computer. One per document and move: failing again keeps the first.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_move_failure')]
#[ORM\Index(name: 'idx_stock_move_failure_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_move_failure', columns: ['company_id', 'move', 'document_id'])]
class StockMoveFailure implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: FailedMove::class)]
    private FailedMove $move;

    /** The delivery note, invoice or credit note, which the failure outlives only until it is settled. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $documentId;

    /** The document's number as it was when the move failed, which names it on « À surveiller ». */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $number;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $failedAt;

    public function __construct(Company $company, FailedMove $move, Uuid $documentId, string $number, \DateTimeImmutable $failedAt)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->move = $move;
        $this->documentId = $documentId;
        $this->number = $number;
        $this->failedAt = $failedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getMove(): FailedMove
    {
        return $this->move;
    }

    public function getDocumentId(): Uuid
    {
        return $this->documentId;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getFailedAt(): \DateTimeImmutable
    {
        return $this->failedAt;
    }
}
