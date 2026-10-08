<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Scheduler;

use App\Module\Recurring\Application\RunRecurringInvoices;
use App\Module\Recurring\Infrastructure\Module\RecurringModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * The recurring invoices' pass, every hour on the worker's schedule: each company's day turns at its own midnight, so
 * an hourly pass drafts an occurrence early on its day wherever the company is, and a pass the worker missed is made
 * up by the next. One company failing is logged and the others still run.
 */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class RunRecurringInvoicesEveryHour
{
    public function __construct(private CompanyRepository $companies, private ModuleStates $modules, private RunRecurringInvoices $run, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        foreach ($this->companies->all() as $company) {
            if (!$company->isActive() || !$this->modules->isEnabled($company->getId(), RecurringModule::KEY)) {
                continue;
            }
            try {
                $made = $this->run->handle($company);
                if ($made > 0) {
                    $this->logger->info('{count} recurring invoices drafted at {company}', ['count' => $made, 'company' => $company->getId()->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The recurring invoices of {company} did not run: {reason}', ['company' => $company->getId()->toRfc4122(), 'reason' => $failure->getMessage(), 'exception' => $failure]);
            }
        }
    }
}
