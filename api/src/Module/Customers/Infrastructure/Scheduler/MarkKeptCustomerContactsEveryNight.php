<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Scheduler;

use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Infrastructure\DataHealth\KeptCustomerContacts;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\Contacts\KeptContactLines;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Uid\Uuid;

/**
 * Marks « À vérifier » every customer keeping an e-mail or a phone, its own or a contact's, that today's rules refuse
 * (row 208), once a day at the data check's hour, with a line for each in its company's language; the data check
 * itself only reads. A customer already marked is left alone, so the night writes each mark once. Only an active
 * company with the customers module on is marked; one customer failing is logged and the others still run.
 */
#[AsPeriodicTask(frequency: '1 day', from: '%app.data_check.at%')]
final readonly class MarkKeptCustomerContactsEveryNight
{
    public function __construct(
        private KeptCustomerContacts $kept,
        private KeptContactLines $lines,
        private CompanyRepository $companies,
        private ModuleStates $modules,
        private ManageCustomers $customers,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        /** @var array<string, array{company: Uuid, lines: list<string>}> $found by customer */
        $found = [];
        $locales = [];
        foreach ($this->kept->all() as $kept) {
            $company = $kept['company']->toRfc4122();
            if (!\array_key_exists($company, $locales)) {
                $owner = $this->companies->ofId($kept['company']);
                $locales[$company] = null !== $owner && $owner->isActive() && $this->modules->isEnabled($owner->getId(), CustomersModule::KEY) ? $owner->getLocale() : null;
            }
            if (null === $locales[$company]) {
                continue;
            }
            $customer = $kept['customer']->toRfc4122();
            $found[$customer]['company'] = $kept['company'];
            $found[$customer]['lines'][] = $this->lines->line($kept['field'], $kept['value'], $kept['refusal'], $locales[$company], null !== $kept['contact'], $kept['contactName']);
        }
        foreach ($found as $customer => $what) {
            try {
                $owner = $this->companies->ofId($what['company']);
                if (null !== $owner && $this->customers->markToCheck($owner, Uuid::fromString($customer), implode("\n", $what['lines']))) {
                    $this->logger->info('Customer {customer} of {company} marked to check: a contact detail today\'s rules refuse', ['customer' => $customer, 'company' => $what['company']->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->error('Customer {customer} could not be marked to check: {reason}', ['customer' => $customer, 'reason' => $failure->getMessage(), 'exception' => $failure]);
            }
        }
    }
}
