<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Scheduler;

use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Infrastructure\DataHealth\KeptVendorContacts;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\Contacts\KeptContactLines;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Uid\Uuid;

/**
 * Marks « À vérifier » every vendor keeping an e-mail or a phone that today's rules refuse
 * (row 208), once a day at the data check's hour, with a line for each in its company's language; the data check
 * itself only reads. A vendor already marked is left alone, so the night writes each mark once. Only an active
 * company with the vendors module on is marked; one vendor failing is logged and the others still run.
 */
#[AsPeriodicTask(frequency: '1 day', from: '%app.data_check.at%')]
final readonly class MarkKeptVendorContactsEveryNight
{
    public function __construct(
        private KeptVendorContacts $kept,
        private KeptContactLines $lines,
        private CompanyRepository $companies,
        private ModuleStates $modules,
        private ManageVendors $vendors,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        /** @var array<string, array{company: Uuid, lines: list<string>}> $found by vendor */
        $found = [];
        $locales = [];
        foreach ($this->kept->all() as $kept) {
            $company = $kept['company']->toRfc4122();
            if (!\array_key_exists($company, $locales)) {
                $owner = $this->companies->ofId($kept['company']);
                $locales[$company] = null !== $owner && $owner->isActive() && $this->modules->isEnabled($owner->getId(), VendorsModule::KEY) ? $owner->getLocale() : null;
            }
            if (null === $locales[$company]) {
                continue;
            }
            $vendor = $kept['vendor']->toRfc4122();
            $found[$vendor]['company'] = $kept['company'];
            $found[$vendor]['lines'][] = $this->lines->line($kept['field'], $kept['value'], $kept['refusal'], $locales[$company]);
        }
        foreach ($found as $vendor => $what) {
            try {
                $owner = $this->companies->ofId($what['company']);
                if (null !== $owner && $this->vendors->markToCheck($owner, Uuid::fromString($vendor), implode("\n", $what['lines']))) {
                    $this->logger->info('Vendor {vendor} of {company} marked to check: a contact detail today\'s rules refuse', ['vendor' => $vendor, 'company' => $what['company']->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->error('Vendor {vendor} could not be marked to check: {reason}', ['vendor' => $vendor, 'reason' => $failure->getMessage(), 'exception' => $failure]);
            }
        }
    }
}
