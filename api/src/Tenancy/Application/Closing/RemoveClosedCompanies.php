<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\Files;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Clock\ClockInterface;

/**
 * Removes every company closed down whose last day kept has passed, each in a transaction of its own: every row that is
 * its own goes with it, then the bytes of its files once that is committed, for bytes without a record are only unused.
 * The journal keeps its entries, which name the company by its id only, and the removal itself.
 */
final readonly class RemoveClosedCompanies
{
    public const string REMOVED = 'company.removed';

    public function __construct(
        private CompanyRepository $companies,
        private Files $files,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many companies were removed */
    public function handle(): int
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $removed = 0;
        foreach ($this->companies->closedDownKeptUntilBefore($today) as $company) {
            $companyId = $company->getId();
            $keys = $this->transactions->run(function () use ($company, $companyId): array {
                $keys = $this->files->storageKeysOf($companyId);
                $this->companies->removeWhole($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $companyId, self::REMOVED, null));

                return $keys;
            });
            $this->files->deleteContents($keys);
            ++$removed;
        }

        return $removed;
    }
}
