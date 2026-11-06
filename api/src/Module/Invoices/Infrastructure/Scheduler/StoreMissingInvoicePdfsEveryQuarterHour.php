<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Scheduler;

use App\Module\Invoices\Application\Print\PrintInvoice;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * An issued invoice or credit note whose PDF the renderer or the storage could not take at issue has it stored by the
 * worker within the quarter hour, rather than at a first download that may never come: the issued page is then kept as
 * it was issued, and nothing is left unsaid in a log alone. Storing is idempotent (a document keeping a PDF is passed
 * over), so a pass the worker missed is made up by the next. A company whose renderer fails is logged and the others
 * still run.
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class StoreMissingInvoicePdfsEveryQuarterHour
{
    public function __construct(
        private CompanyRepository $companies,
        private ModuleStates $modules,
        private PrintInvoice $print,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.documents.pdf_catch_up_batch')]
        private int $batch,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->companies->all() as $company) {
            if (!$company->isActive() || !$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
                continue;
            }
            try {
                $pass = $this->print->storeMissing($company->getId(), $this->batch);
                foreach ($pass->failed as $number => $reason) {
                    $this->logger->warning('The PDF of invoice {number} at {company} was not stored; the next pass tries again: {reason}', ['number' => $number, 'company' => $company->getId()->toRfc4122(), 'reason' => $reason]);
                }
                if ($pass->stored > 0) {
                    $this->logger->info('{count} invoice PDFs stored after their issue at {company}', ['count' => $pass->stored, 'company' => $company->getId()->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->warning('The invoice PDFs of {company} still to store were not stored; the next pass tries again: {reason}', ['company' => $company->getId()->toRfc4122(), 'reason' => $failure->getMessage()]);
            }
        }
    }
}
