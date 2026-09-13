<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * How one establishment numbers one document type (docs/SPEC.md § 7, 2026-09-13): a format, the number the sequence
 * resumes at and when it starts again. A row rather than a setting, because gapless numbering locks it in the issuing
 * transaction; that allocation arrives with the first numbered document (G6), together with the reset bookkeeping.
 * A company starts with one default series per document type its preset numbers, on its default establishment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'numbering_series')]
#[ORM\Index(name: 'idx_numbering_series_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_numbering_series_default', columns: ['establishment_id', 'document_type'], options: ['where' => '(is_default)'])]
class NumberingSeries
{
    public const string DOCUMENT_TYPE = '/^[a-z][a-z_]{0,31}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, onDelete: 'CASCADE')]
    private Establishment $establishment;

    #[ORM\Column(length: 32)]
    private string $documentType;

    #[ORM\Column(length: NumberFormat::MAX_LENGTH)]
    private string $format;

    #[ORM\Column]
    private int $nextNumber = 1;

    #[ORM\Column(length: 16, enumType: ResetPeriod::class)]
    private ResetPeriod $resetPeriod;

    /** The period the sequence last started again in; kept by the allocation (G6). */
    #[ORM\Column(nullable: true)]
    private ?int $lastResetYear = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $lastResetMonth = null;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, Establishment $establishment, string $documentType, \DateTimeImmutable $now)
    {
        if (1 !== preg_match(self::DOCUMENT_TYPE, $documentType)) {
            throw new InvalidNumbering('documentType', \sprintf('"%s" is not a document type.', $documentType));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->establishment = $establishment;
        $this->documentType = $documentType;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidNumbering */
    public static function create(Company $company, Establishment $establishment, string $documentType, NumberFormat $format, ResetPeriod $resetPeriod, bool $isDefault, \DateTimeImmutable $now): self
    {
        $series = new self($company, $establishment, $documentType, $now);
        $series->format = $format->pattern;
        $series->resetPeriod = $resetPeriod;
        $series->isDefault = $isDefault;

        return $series;
    }

    /**
     * Until documents are numbered (G6) the sequence may resume anywhere from one, so a company moving from another
     * tool continues where it stopped.
     *
     * @return bool whether anything changed
     *
     * @throws InvalidNumbering
     */
    public function revise(NumberFormat $format, ResetPeriod $resetPeriod, int $nextNumber, \DateTimeImmutable $now): bool
    {
        if ($nextNumber < 1) {
            throw new InvalidNumbering('nextNumber', 'A sequence resumes at one or above.');
        }
        if ($format->pattern === $this->format && $resetPeriod === $this->resetPeriod && $nextNumber === $this->nextNumber) {
            return false;
        }
        $this->format = $format->pattern;
        $this->resetPeriod = $resetPeriod;
        $this->nextNumber = $nextNumber;
        $this->updatedAt = $now;

        return true;
    }

    /** The number the next document would carry if it were issued on that day. */
    public function preview(\DateTimeImmutable $date): string
    {
        return new NumberFormat($this->format)->render($this->nextNumber, $date, $this->establishment->getCode());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getNextNumber(): int
    {
        return $this->nextNumber;
    }

    public function getResetPeriod(): ResetPeriod
    {
        return $this->resetPeriod;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
