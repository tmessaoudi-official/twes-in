<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Import;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowRejected;
use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Module\Vendors\Domain\VendorRepository;
use App\Module\Vendors\Infrastructure\ApiPlatform\VendorPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a vendor file holds, for one company, and what one of its rows does (docs/SPEC.md § 8 row 59).
 *
 * As for customers, the registration numbers a row may carry are the company's preset's to say, which is why a
 * template is generated per company rather than shipped. A row names the expense category its bills usually go to by
 * name, through the directory port the vendors module already reads it with, so nothing here imports the expenses
 * module.
 *
 * A row is written through ManageVendors, the use case the vendor form uses, so a file is held to every rule a person
 * is. A row whose number is a vendor's already updates it in upsert mode, from the cells the row fills in only: a
 * blank cell keeps what is there.
 */
final readonly class VendorImport implements DeclaresImport
{
    public const string KEY = 'vendors';

    /** The column each field a refusal names is read from, and the code that refusal carries. */
    private const array REFUSAL_OF = [
        'number' => ['number', 'invalid_number'],
        'name' => ['name', 'invalid_name'],
        'legalName' => ['legal_name', 'invalid_legal_name'],
        'email' => ['email', 'invalid_email'],
        'phone' => ['phone', 'invalid_phone'],
        'website' => ['website', 'invalid_website'],
        'iban' => ['iban', 'invalid_iban'],
        'bic' => ['bic', 'invalid_bic'],
        'paymentTermsDays' => ['payment_terms_days', 'invalid_payment_terms'],
        'defaultExpenseCategoryId' => ['expense_category', 'unknown_expense_category'],
    ];

    private const array YES = ['yes', 'y', 'true', '1', 'oui', 'o'];
    private const array NO = ['no', 'n', 'false', '0', 'non'];

    public function __construct(
        private FiscalPresets $presets,
        private ManageVendors $manage,
        private VendorRepository $vendors,
        private ExpenseCategoryDirectory $expenseCategories,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return VendorPermission::WRITE;
    }

    public function module(): string
    {
        return VendorsModule::KEY;
    }

    public function identityColumn(): string
    {
        return 'number';
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [...$this->fixed(), ...$this->identifiers($company)]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported
    {
        $number = $record->value('number') ?? throw new RowRejected('number', 'A vendor is found again by its number, so every row needs one.', 'value_required');
        $existing = $this->vendors->ofNumberInCompany($number, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw new RowRejected('number', 'A vendor already has this number. Import in "create and update" mode to update it.', 'already_exists');
        }

        $written = null;
        try {
            if (null === $existing) {
                $written = $this->manage->create($company, $this->input($company, $record, null), $actorUserId);

                return RowImported::Created;
            }
            $written = $this->manage->revise($company, $existing->getId(), $this->input($company, $record, $existing), $actorUserId);

            return RowImported::Updated;
        } catch (InvalidVendor $refused) {
            [$column, $code] = self::refusalOf($refused->field);

            throw new RowRejected($column, $refused->getMessage(), 'invalid_value' === $refused->reason ? $code : $refused->reason, $refused->params);
        } catch (VendorNumberTaken) {
            throw new RowRejected('number', 'A vendor already has this number.', 'already_exists');
        } finally {
            // Doctrine's batch processing: every flush walks every managed entity, so a row's vendor stays out of the
            // unit of work once written, or a file costs the square of its length. Nothing reads it back here.
            foreach ([$existing, $written] as $vendor) {
                if (null !== $vendor) {
                    $this->entityManager->detach($vendor);
                }
            }
        }
    }

    /**
     * The vendor the row describes: every cell it fills in, and for an existing vendor what it already holds wherever
     * the row leaves a cell blank.
     *
     * @throws InvalidVendor
     * @throws RowRejected
     */
    private function input(Company $company, ImportRecord $record, ?Vendor $current): VendorInput
    {
        $held = $current?->getProfile();
        $identifiers = $held->identifiers ?? [];
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $identifiers[$identifier->key] = $record->value($identifier->key) ?? $identifiers[$identifier->key] ?? null;
        }

        return new VendorInput(
            $record->value('number') ?? '',
            new VendorProfile(
                $record->value('name') ?? $held->name ?? '',
                $record->value('legal_name') ?? $held?->legalName,
                $identifiers,
                $record->value('email') ?? $held?->email,
                $record->value('phone') ?? $held?->phone,
                $record->value('website') ?? $held?->website,
                $this->address($company, $record, $held?->address),
                $record->value('iban') ?? $held?->iban,
                $record->value('bic') ?? $held?->bic,
                self::wholeNumber($record, 'payment_terms_days') ?? $held?->paymentTermsDays,
                $record->value('notes') ?? $held?->notes,
                $this->expenseCategoryId($company, $record, $held),
            ),
            self::yesNo($record, 'active') ?? $current?->isActive() ?? true,
        );
    }

    /** The address the row fills in over the one held, in the company's country when neither names one. */
    private function address(Company $company, ImportRecord $record, ?PostalAddress $held): PostalAddress
    {
        $address = new PostalAddress(
            $record->value('line1') ?? $held?->line1,
            $record->value('line2') ?? $held?->line2,
            $record->value('postal_code') ?? $held?->postalCode,
            $record->value('city') ?? $held?->city,
            $record->value('country_code') ?? $held?->countryCode,
        );
        if ($address->isEmpty()) {
            return new PostalAddress();
        }

        return null !== $address->countryCode ? $address : new PostalAddress($address->line1, $address->line2, $address->postalCode, $address->city, $company->getCountryCode());
    }

    private function expenseCategoryId(Company $company, ImportRecord $record, ?VendorProfile $held): ?Uuid
    {
        $name = $record->value('expense_category');
        if (null === $name) {
            return $held?->defaultExpenseCategoryId;
        }

        return $this->expenseCategories->idOfActiveNameInCompany($name, $company->getId())
            ?? throw new RowRejected('expense_category', \sprintf('The company has no active expense category named "%s". Create it first.', $name), 'unknown_expense_category', ['name' => $name]);
    }

    /**
     * The columns every company has. `number` is required because a vendor is found again by it on a second import.
     *
     * @return list<ImportColumn>
     */
    private function fixed(): array
    {
        return [
            new ImportColumn('number', 'import.vendors.number', true, 'FRN-0001', 'import.vendors.number_note'),
            new ImportColumn('name', 'import.vendors.name', true, 'Aciers du Sud'),
            new ImportColumn('legal_name', 'import.vendors.legal_name', false, 'Aciers du Sud SARL'),
            new ImportColumn('email', 'import.vendors.email', false, 'compta@aciers.tn'),
            new ImportColumn('phone', 'import.vendors.phone', false, '+216 71 000 000'),
            new ImportColumn('website', 'import.vendors.website', false, 'https://aciers.tn'),
            new ImportColumn('line1', 'import.vendors.line1', false, '12 rue de la Fonderie'),
            new ImportColumn('line2', 'import.vendors.line2'),
            new ImportColumn('postal_code', 'import.vendors.postal_code', false, '2033'),
            new ImportColumn('city', 'import.vendors.city', false, 'Megrine'),
            new ImportColumn('country_code', 'import.vendors.country_code', false, 'TN', 'import.country_note'),
            new ImportColumn('iban', 'import.vendors.iban', false, 'TN5904018104003691000123'),
            new ImportColumn('bic', 'import.vendors.bic', false, 'BIATTNTT'),
            new ImportColumn('payment_terms_days', 'import.vendors.payment_terms_days', false, '30', 'import.vendors.payment_terms_note'),
            new ImportColumn('expense_category', 'import.vendors.expense_category', false, null, 'import.vendors.expense_category_note'),
            new ImportColumn('notes', 'import.vendors.notes'),
            new ImportColumn('active', 'import.vendors.active', false, 'yes', 'import.boolean_note'),
        ];
    }

    /**
     * The registration numbers this company's preset asks for, each under its own key, headed by the preset's own
     * label, so a Tunisian company reads its own where a French one reads "SIRET".
     *
     * @return list<ImportColumn>
     */
    private function identifiers(Company $company): array
    {
        $columns = [];
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $columns[] = new ImportColumn($identifier->key, $identifier->labelKey, false, null, 'import.identifier_note', ImportHeading::FiscalLabel);
        }

        return $columns;
    }

    /** @return array{0: ?string, 1: string} */
    private static function refusalOf(string $field): array
    {
        if (str_starts_with($field, 'identifiers.')) {
            return [substr($field, \strlen('identifiers.')), 'invalid_identifier'];
        }
        if (str_starts_with($field, 'address.')) {
            return [substr($field, \strlen('address.')), 'invalid_address'];
        }

        return self::REFUSAL_OF[$field] ?? [null, 'invalid_value'];
    }

    /** @throws RowRejected when the cell is neither a yes nor a no */
    private static function yesNo(ImportRecord $record, string $column): ?bool
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $cell = mb_strtolower($cell);

        return match (true) {
            \in_array($cell, self::YES, true) => true,
            \in_array($cell, self::NO, true) => false,
            default => throw new RowRejected($column, 'Yes or no.', 'not_yes_or_no'),
        };
    }

    /** @throws RowRejected when the cell is not a whole number */
    private static function wholeNumber(ImportRecord $record, string $column): ?int
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $cell = trim($cell);

        return 1 === preg_match('/^-?\d+$/', $cell) ? (int) $cell : throw new RowRejected($column, 'A whole number of days.', 'not_a_whole_number');
    }
}
