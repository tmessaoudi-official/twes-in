<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\InvalidClosing;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Closes the company's books through a day (the accountant's closed period): from then on no document is dated on or
 * before it. The day moves forward only and never reaches the company's today. Only a change is saved and audited,
 * with the field's name and never the day, as every entry records.
 */
final readonly class ClosePeriod
{
    public const string CLOSED = 'company.period_closed';

    public function __construct(
        private CompanyRepository $companies,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @throws InvalidClosing */
    public function handle(Company $company, \DateTimeImmutable $through, ?Uuid $actorUserId): Company
    {
        return $this->transactions->run(function () use ($company, $through, $actorUserId): Company {
            $now = $this->clock->now();
            $today = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
            if ($company->closeThrough($through, $today, $now)) {
                $this->companies->save($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::CLOSED, $actorUserId, ['fields' => ['closedThrough']], $company->getId()));
            }

            return $company;
        });
    }
}
