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
use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Domain\ValueObject\VendorSearch;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's vendors. The registration numbers a vendor carries are those the company's preset knows, each in its
 * shape, and none is required: a supplier's number is recorded from its invoice, never demanded before buying.
 * Audited with the names of the fields a revision changed, never their values: a vendor may be a private person. A
 * default expense category, when named, is an active one of the company; one that was deactivated since stays.
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
        private ExpenseCategoryDirectory $expenseCategories,
        private Transactions $transactions,
        private RecordVersions $versions,
    ) {
    }

    /** @return Page<Vendor> */
    public function search(Company $company, VendorSearch $search, PageRequest $page): Page
    {
        return $this->vendors->search($company->getId(), $search, $page);
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
        $input = $this->withNumbersAsStored($company, $input);

        return $this->transactions->run(function () use ($company, $input, $actorUserId): Vendor {
            if (null !== $this->vendors->ofNumberInCompany(TypedText::trimmed($input->number), $company->getId())) {
                throw new VendorNumberTaken();
            }
            $this->check($company, $input, null);
            $vendor = Vendor::create($company, $input->number, $input->profile, $this->clock->now());
            if (!$input->isActive) {
                $vendor->revise($input->number, $input->profile, false, $this->clock->now());
            }
            $this->writeCheck($vendor, $input);
            $this->vendors->save($vendor);
            $this->record($company, $vendor->getId(), self::CREATED, [], $actorUserId);

            return $vendor;
        });
    }

    /**
     * `$version` is the one the editor opened; an import, which revises what it finds, names none.
     *
     * @throws VendorNotFound
     * @throws VendorNumberTaken
     * @throws VendorMergedAway
     * @throws InvalidVendor
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, VendorInput $input, ?Uuid $actorUserId, ?int $version = null): Vendor
    {
        $input = $this->withNumbersAsStored($company, $input);

        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId, $version): Vendor {
            $vendor = $this->get($company, $id);
            if (null !== $version) {
                $this->versions->expect($vendor, $version);
            }
            if ($vendor->isMergedAway()) {
                throw new VendorMergedAway(\sprintf('The vendor %s was merged into %s: it is no longer revised.', $vendor->getNumber(), $vendor->standingFor()->getNumber()));
            }
            $holder = $this->vendors->ofNumberInCompany(TypedText::trimmed($input->number), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($vendor->getId())) {
                throw new VendorNumberTaken();
            }
            $this->check($company, $input, $vendor);

            // After the name, whose rename settles a doubt about it.
            $changed = [...$vendor->revise($input->number, $input->profile, $input->isActive, $this->clock->now()), ...$this->writeCheck($vendor, $input)];
            if ([] !== $changed) {
                $this->vendors->save($vendor);
                $this->record($company, $vendor->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $vendor;
        });
    }

    /**
     * @return list<string> the fields that changed
     *
     * @throws InvalidVendor
     */
    private function writeCheck(Vendor $vendor, VendorInput $input): array
    {
        $now = $this->clock->now();
        $changed = null === $input->mark ? [] : $vendor->reviseCheck($input->mark, $input->nameDoubtful ?? true, $now);
        if (null === $input->mark && false === $input->nameDoubtful) {
            $changed = $vendor->reviseCheck($vendor->getCheckMark(), false, $now);
        }
        if ($input->doubtsName && !$vendor->isNameDoubtful()) {
            $vendor->markNameDoubtful($now);
            $changed[] = 'nameDoubtful';
        }

        return array_values(array_unique($changed));
    }

    /** The input with its registration numbers as the preset stores them, however they were written. */
    private function withNumbersAsStored(Company $company, VendorInput $input): VendorInput
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        return $input->withProfile($input->profile->withIdentifiers(IdentifierRules::normalised($preset, $input->profile->identifiers, '')));
    }

    private function check(Company $company, VendorInput $input, ?Vendor $current): void
    {
        $refusal = IdentifierRules::refusal($this->presets->get($company->getFiscalPreset()), $input->profile->identifiers, '');
        if (null !== $refusal) {
            throw new InvalidVendor($refusal->field, $refusal->message, $refusal->reason, $refusal->params);
        }
        $category = $input->profile->defaultExpenseCategoryId;
        $kept = null !== $category && null !== $current && true === $current->getProfile()->defaultExpenseCategoryId?->equals($category);
        if (null !== $category && !$kept && !$this->expenseCategories->isActiveInCompany($category, $company->getId())) {
            throw new InvalidVendor('defaultExpenseCategoryId', 'No active expense category of this company has this id.', 'unknown_choice');
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $vendorId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $vendorId, $action, $actorUserId, $changes, $company->getId()));
    }
}
