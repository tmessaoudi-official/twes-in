<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Scheduler;

use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\Module\DeliveryNotesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * A validated note whose PDF the renderer or the storage could not take at validation has it stored by the worker
 * within the quarter hour, rather than at a first download that may never come: the issued page is then kept as it was
 * issued, and nothing is left unsaid in a log alone. Storing is idempotent (a note keeping a PDF is passed over), so a
 * pass the worker missed is made up by the next. A company whose renderer fails is logged and the others still run.
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class StoreMissingDeliveryNotePdfsEveryQuarterHour
{
    public function __construct(
        private CompanyRepository $companies,
        private ModuleStates $modules,
        private PrintDeliveryNote $print,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.documents.pdf_catch_up_batch')]
        private int $batch,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->companies->all() as $company) {
            if (!$company->isActive() || !$this->modules->isEnabled($company->getId(), DeliveryNotesModule::KEY)) {
                continue;
            }
            try {
                $pass = $this->print->storeMissing($company->getId(), $this->batch);
                foreach ($pass->failed as $number => $reason) {
                    $this->logger->warning('The PDF of delivery note {number} at {company} was not stored; the next pass tries again: {reason}', ['number' => $number, 'company' => $company->getId()->toRfc4122(), 'reason' => $reason]);
                }
                if ($pass->stored > 0) {
                    $this->logger->info('{count} delivery note PDFs stored after their validation at {company}', ['count' => $pass->stored, 'company' => $company->getId()->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->warning('The delivery note PDFs of {company} still to store were not stored; the next pass tries again: {reason}', ['company' => $company->getId()->toRfc4122(), 'reason' => $failure->getMessage()]);
            }
        }
    }
}
