<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberingSeriesRepository;
use Psr\Clock\ClockInterface;

/**
 * Takes the next number an establishment gives a document type, on the company's own day, from its default series
 * (docs/SPEC.md § 3 Money: numbering is gapless and assigned at issue). It runs inside the transaction that stores
 * the numbered document: the series stays locked until that transaction ends, so two documents never take one number,
 * and a document that fails to be stored gives its number back with the rollback.
 */
final readonly class AllocateNumber
{
    public function __construct(
        private NumberingSeriesRepository $series,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NoNumberingSeries when the establishment numbers no such document
     * @throws InvalidNumbering  when the company's day comes before the month of the series' last number
     */
    public function allocate(Company $company, Establishment $establishment, string $documentType): AllocatedNumber
    {
        if (!$this->transactions->active()) {
            throw new \LogicException('A number is taken inside the transaction that stores its document.');
        }
        if (!$establishment->getCompany()->getId()->equals($company->getId())) {
            throw new \LogicException('An establishment numbers the documents of its own company.');
        }
        $series = $this->series->lockedDefaultFor($establishment->getId(), $documentType)
            ?? throw new NoNumberingSeries(\sprintf('The establishment %s numbers no %s.', $establishment->getCode(), $documentType));

        $now = $this->clock->now();
        $day = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
        $number = $series->allocate($day, $now);
        $this->series->save($series);

        return new AllocatedNumber($number, new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC')));
    }
}
