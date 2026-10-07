<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberingSeriesRepository;
use Psr\Clock\ClockInterface;

/**
 * The number a document issued now would carry, said before issuing and never taken: AllocateNumber takes it, inside
 * the transaction that stores the document, and a document issued in between takes this one first.
 */
final readonly class NextNumber
{
    public function __construct(private NumberingSeriesRepository $series, private ClockInterface $clock)
    {
    }

    /**
     * @throws NoNumberingSeries when the establishment numbers no such document
     * @throws InvalidNumbering  when the company's day comes before the month of the series' last number
     */
    public function of(Company $company, Establishment $establishment, string $documentType): string
    {
        if (!$establishment->getCompany()->getId()->equals($company->getId())) {
            throw new \LogicException('An establishment numbers the documents of its own company.');
        }
        $series = $this->series->defaultFor($establishment->getId(), $documentType)
            ?? throw new NoNumberingSeries(\sprintf('The establishment %s numbers no %s.', $establishment->getCode(), $documentType));

        return $series->numberFor($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone())));
    }
}
