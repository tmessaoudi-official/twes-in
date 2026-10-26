<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\NumberingSeries;
use App\Tenancy\Domain\Repository\NumberingSeriesRepository;
use App\Tenancy\Domain\ValueObject\NumberFormat;
use App\Tenancy\Domain\ValueObject\ResetPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** A company's numbering series: listed with the number each would issue next, and revised, each change audited. */
final readonly class ManageNumberingSeries implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'numbering_series';
    public const string REVISED = 'numbering_series.revised';

    public function __construct(
        private NumberingSeriesRepository $series,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
    ) {
    }

    /** @return list<NumberingSeries> by establishment code, then document type */
    public function list(Company $company): array
    {
        return $this->series->ofCompany($company->getId());
    }

    /** The day a preview numbers for. */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @throws NumberingSeriesNotFound for a series that does not exist or belongs to another company
     * @throws InvalidNumbering
     * @throws NumberingSeriesOverlap  for a format that could print a number another series of the document prints
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $seriesId, NumberingChanges $changes, ?Uuid $actorUserId, ?int $version = null): NumberingSeries
    {
        return $this->transactions->run(function () use ($company, $seriesId, $changes, $actorUserId, $version): NumberingSeries {
            $series = $this->series->ofIdInCompany($seriesId, $company->getId()) ?? throw new NumberingSeriesNotFound('No such numbering series.');
            if (null !== $version) {
                $this->versions->expect($series, $version);
            }
            $reset = ResetPeriod::tryFrom($changes->resetPeriod)
                ?? throw new InvalidNumbering('resetPeriod', \sprintf('"%s" is not a reset period: %s.', $changes->resetPeriod, implode(', ', array_map(static fn (ResetPeriod $p): string => $p->value, ResetPeriod::cases()))));

            $format = new NumberFormat($changes->format);
            // One document type's numbers are one company's, whichever establishment issues them: the refusal comes
            // here, where the format is written, and not at the issue its number would collide on.
            foreach ($this->series->ofCompany($company->getId()) as $other) {
                if ($other->getDocumentType() !== $series->getDocumentType() || $other->getId()->equals($series->getId())) {
                    continue;
                }
                $code = $other->getEstablishment()->getCode();
                if ($format->mayPrintAsOf(new NumberFormat($other->getFormat()), $series->getEstablishment()->getCode(), $code)) {
                    throw new NumberingSeriesOverlap(\sprintf('Numbers of this format could be those of the %s series of establishment %s (%s): give one of them {EST}, or text the other never prints.', $other->getDocumentType(), $code, $other->getFormat()));
                }
            }
            if ($series->revise($format, $reset, $changes->nextNumber, $this->clock->now())) {
                $this->series->save($series);
                $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $series->getId(), self::REVISED, $actorUserId, ['format' => $series->getFormat(), 'next_number' => $series->getNextNumber(), 'reset_period' => $series->getResetPeriod()->value], $company->getId()));
            }

            return $series;
        });
    }

    public function auditActions(): array
    {
        return [
            self::REVISED => AuditScope::Company,
        ];
    }
}
