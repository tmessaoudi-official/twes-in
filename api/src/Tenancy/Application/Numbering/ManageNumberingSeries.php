<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberFormat;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\NumberingSeriesRepository;
use App\Tenancy\Domain\ResetPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** A company's numbering series: listed with the number each would issue next, and revised, each change audited. */
final readonly class ManageNumberingSeries
{
    public const string ENTITY_TYPE = 'numbering_series';
    public const string REVISED = 'numbering_series.revised';

    public function __construct(
        private NumberingSeriesRepository $series,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
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
     */
    public function revise(Company $company, Uuid $seriesId, NumberingChanges $changes, ?Uuid $actorUserId): NumberingSeries
    {
        return $this->transactions->run(function () use ($company, $seriesId, $changes, $actorUserId): NumberingSeries {
            $series = $this->series->ofIdInCompany($seriesId, $company->getId()) ?? throw new NumberingSeriesNotFound('No such numbering series.');
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
}
