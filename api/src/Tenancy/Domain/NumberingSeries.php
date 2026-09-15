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
 * transaction (AllocateNumber). Once a document carries a number from it, the sequence resumes where it stands: its
 * format and reset period still change, the next number no longer does, so no number is skipped or issued twice.
 * A company starts with one default series per document type its preset numbers, on its default establishment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'numbering_series')]
#[ORM\Index(name: 'idx_numbering_series_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_numbering_series_establishment', columns: ['establishment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_numbering_series_default', columns: ['establishment_id', 'document_type'], options: ['where' => 'is_default'])]
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

    /** The year and month of the last number issued; null until a document carries one. */
    #[ORM\Column(name: 'last_reset_year', nullable: true)]
    private ?int $lastNumberedYear = null;

    #[ORM\Column(name: 'last_reset_month', type: Types::SMALLINT, nullable: true)]
    private ?int $lastNumberedMonth = null;

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
        self::assertPrintsItsPeriod($format, $resetPeriod);
        $series = new self($company, $establishment, $documentType, $now);
        $series->format = $format->pattern;
        $series->resetPeriod = $resetPeriod;
        $series->isDefault = $isDefault;

        return $series;
    }

    /**
     * Until a document carries a number from it the sequence may resume anywhere from one, so a company moving from
     * another tool continues where it stopped; afterwards it resumes where it stands.
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
        self::assertPrintsItsPeriod($format, $resetPeriod);
        if ($this->isNumbered() && $nextNumber !== $this->nextNumber) {
            throw new InvalidNumbering('nextNumber', \sprintf('Documents already carry numbers from this series: it resumes at %d, so no number is skipped or issued twice.', $this->nextNumber));
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

    /**
     * The number a document issued on that day carries, and the sequence moved on. The day is the company's own. The
     * sequence starts again at one on the first number of a later year (yearly) or month (monthly); the first number
     * ever issued resumes where the company left the sequence. A day before the month of the last number is refused,
     * because starting again there would issue that month's numbers twice.
     *
     * @throws InvalidNumbering
     */
    public function allocate(\DateTimeImmutable $issueDay, \DateTimeImmutable $now): string
    {
        $period = [(int) $issueDay->format('Y'), (int) $issueDay->format('n')];
        if (null !== $this->lastNumberedYear) {
            $last = [$this->lastNumberedYear, $this->lastNumberedMonth ?? 1];
            if ($period < $last) {
                throw new InvalidNumbering('issueDate', \sprintf('A number cannot be issued on %s, before %04d-%02d, the month of the last one.', $issueDay->format('Y-m-d'), $last[0], $last[1]));
            }
            $startsAgain = match ($this->resetPeriod) {
                ResetPeriod::Yearly => $period[0] > $last[0],
                ResetPeriod::Monthly => $period > $last,
                ResetPeriod::Never => false,
            };
            if ($startsAgain) {
                $this->nextNumber = 1;
            }
        }
        $number = $this->preview($issueDay);
        ++$this->nextNumber;
        [$this->lastNumberedYear, $this->lastNumberedMonth] = $period;
        $this->updatedAt = $now;

        return $number;
    }

    /**
     * A sequence that starts again at one prints the period it starts again in: otherwise the first number of a new
     * year or month is a number the series already gave, and every document of that period is refused as taken.
     *
     * @throws InvalidNumbering
     */
    private static function assertPrintsItsPeriod(NumberFormat $format, ResetPeriod $resetPeriod): void
    {
        $message = match ($resetPeriod) {
            ResetPeriod::Yearly => $format->printsYear() ? null : 'A series that starts again each year prints the year, {YYYY} or {YY}: otherwise the first number of a new year is one it already gave.',
            ResetPeriod::Monthly => $format->printsYear() && $format->printsMonth() ? null : 'A series that starts again each month prints the month and the year, {MM} with {YYYY} or {YY}: otherwise the first number of a new month is one it already gave.',
            ResetPeriod::Never => null,
        };
        if (null !== $message) {
            throw new InvalidNumbering('format', $message);
        }
    }

    /** Whether a document carries a number from this series. */
    public function isNumbered(): bool
    {
        return null !== $this->lastNumberedYear;
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
