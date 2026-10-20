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
 * A file a company imported: what it was, by its content's SHA-256, who sent it and what it did. Kept for committed
 * imports only, since a preview and a refused file leave nothing behind. The next preview of the very same file is
 * told it was already imported, and what the import wrote elsewhere (a stock movement) can carry its id, so it reads
 * as the import's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(name: 'idx_import_run_content', columns: ['company_id', 'subject', 'content_hash'])]
class ImportRun implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $subject;

    /** The SHA-256 of the file's bytes, in lowercase hexadecimal. */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 16)]
    private string $mode;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $importedBy;

    #[ORM\Column]
    private int $created;

    #[ORM\Column]
    private int $updated;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $at;

    /**
     * The file's own headings a person paired with the subject's columns, by the column key each was read as ('' for a
     * column ignored); null when the file needed none, so the next file is offered the last pairing a person made.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pairing;

    /** When « Annuler cet import » took back what it did; null while it stands, and again once the bin puts it back. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $undoneBy = null;

    /** Names what its undo did beside the records (the stock it took back), so the bin's restore finds it again. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $undoId = null;

    /** The removal its undo made of the records it created, which « Récemment supprimés » restores; null when it made none. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $undoRemovalId = null;

    /** @param array<string, string> $pairing */
    public function __construct(Uuid $id, Company $company, string $subject, string $contentHash, string $mode, ?Uuid $importedBy, int $created, int $updated, \DateTimeImmutable $at, array $pairing = [])
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $contentHash)) {
            throw new \InvalidArgumentException('A content hash is a SHA-256 in lowercase hexadecimal.');
        }
        $this->id = $id;
        $this->company = $company;
        $this->subject = $subject;
        $this->contentHash = $contentHash;
        $this->mode = $mode;
        $this->importedBy = $importedBy;
        $this->created = $created;
        $this->updated = $updated;
        $this->at = $at;
        $this->pairing = [] === $pairing ? null : $pairing;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getImportedBy(): ?Uuid
    {
        return $this->importedBy;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    /** @return array<string, string> */
    public function getPairing(): array
    {
        return $this->pairing ?? [];
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    /** Whether « Annuler cet import » may still take it back: within its day, and not already taken back. */
    public function isUndoableAt(\DateTimeImmutable $now, \DateInterval $undoableFor): bool
    {
        return null === $this->undoneAt && $now < $this->at->add($undoableFor);
    }

    public function undo(Uuid $undoId, ?Uuid $by, \DateTimeImmutable $now, ?Uuid $removalId): void
    {
        if (null !== $this->undoneAt) {
            throw new \LogicException('An import is taken back once.');
        }
        $this->undoId = $undoId;
        $this->undoneAt = $now;
        $this->undoneBy = $by;
        $this->undoRemovalId = $removalId;
    }

    /** The bin put back what its undo took: it stands again, and may be taken back again within its day. */
    public function restored(): void
    {
        $this->undoId = null;
        $this->undoneAt = null;
        $this->undoneBy = null;
        $this->undoRemovalId = null;
    }

    public function getUndoId(): ?Uuid
    {
        return $this->undoId;
    }

    public function getUndoneAt(): ?\DateTimeImmutable
    {
        return $this->undoneAt;
    }

    public function getUndoneBy(): ?Uuid
    {
        return $this->undoneBy;
    }

    public function getUndoRemovalId(): ?Uuid
    {
        return $this->undoRemovalId;
    }
}
