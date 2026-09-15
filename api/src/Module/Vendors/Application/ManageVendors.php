<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's vendors. The registration numbers a vendor carries are those the company's preset knows, each in its
 * shape, and none is required: a supplier's number is recorded from its invoice, never demanded before buying.
 * Audited with the names of the fields a revision changed, never their values: a vendor may be a private person.
 */
final readonly class ManageVendors
{
    public const string ENTITY_TYPE = 'vendor';
    public const string CREATED = 'vendor.created';
    public const string REVISED = 'vendor.revised';

    public function __construct(
        private VendorRepository $vendors,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Vendor> */
    public function list(Company $company): array
    {
        return $this->vendors->ofCompany($company->getId());
    }

    /** @throws VendorNotFound */
    public function get(Company $company, Uuid $id): Vendor
    {
        return $this->vendors->ofIdInCompany($id, $company->getId()) ?? throw new VendorNotFound();
    }

    /**
     * @throws VendorNumberTaken
     * @throws InvalidVendor
     */
    public function create(Company $company, VendorInput $input, ?Uuid $actorUserId): Vendor
    {
        if (null !== $this->vendors->ofNumberInCompany(trim($input->number), $company->getId())) {
            throw new VendorNumberTaken();
        }
        $this->checkIdentifiers($company, $input);
        $vendor = Vendor::create($company, $input->number, $input->profile, $this->clock->now());
        if (!$input->isActive) {
            $vendor->revise($input->number, $input->profile, false, $this->clock->now());
        }
        $this->vendors->save($vendor);
        $this->record($company, $vendor->getId(), self::CREATED, [], $actorUserId);

        return $vendor;
    }

    /**
     * @throws VendorNotFound
     * @throws VendorNumberTaken
     * @throws InvalidVendor
     */
    public function revise(Company $company, Uuid $id, VendorInput $input, ?Uuid $actorUserId): Vendor
    {
        $vendor = $this->get($company, $id);
        $holder = $this->vendors->ofNumberInCompany(trim($input->number), $company->getId());
        if (null !== $holder && !$holder->getId()->equals($vendor->getId())) {
            throw new VendorNumberTaken();
        }
        $this->checkIdentifiers($company, $input);

        $changed = $vendor->revise($input->number, $input->profile, $input->isActive, $this->clock->now());
        if ([] !== $changed) {
            $this->vendors->save($vendor);
            $this->record($company, $vendor->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
        }

        return $vendor;
    }

    private function checkIdentifiers(Company $company, VendorInput $input): void
    {
        $refusal = IdentifierRules::refusal($this->presets->get($company->getFiscalPreset()), $input->profile->identifiers, '');
        if (null !== $refusal) {
            throw new InvalidVendor(...$refusal);
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $vendorId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $vendorId, $action, $actorUserId, $changes, $company->getId()));
    }
}
