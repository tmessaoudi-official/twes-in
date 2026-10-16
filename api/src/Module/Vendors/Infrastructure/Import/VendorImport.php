<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Import;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\ImportExport\Infrastructure\Validation\ResourceRules;
use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Module\Vendors\Infrastructure\ApiPlatform\Resource\VendorResource;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\Shared\Domain\Model\PostalAddress;
use App\Tenancy\Domain\Model\Company;
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
 * blank cell keeps what is there. A row setting or changing a vendor's IBAN or BIC asks who is at the screen again, as
 * the vendor form does (docs/SPEC.md § 7, the step-up list): the whole file waits for it, once, and a preview asks
 * nothing.
 */
final readonly class VendorImport implements DeclaresImport
{
    public const string KEY = 'vendors';

    /** The column each field a refusal names is read from, beside the cells the form's rules hold. */
    private const array COLUMN_OF = [
        'number' => 'number',
        'name' => 'name',
        'paymentTermsDays' => 'payment_terms_days',
        'defaultExpenseCategoryId' => 'expense_category',
    ];

    /**
     * The cells the form's rules hold, by the resource property whose rules each is held to: a file meets every rule
     * the form does, the domain's among them, before the use case, and a refusal carries the reason the form gives.
     */
    private const array CELL_RULES = [
        'legalName' => 'legal_name',
        'email' => 'email',
        'phone' => 'phone',
        'website' => 'website',
        'addressLine1' => 'line1',
        'addressLine2' => 'line2',
        'postalCode' => 'postal_code',
        'city' => 'city',
        'countryCode' => 'country_code',
        'iban' => 'iban',
        'bic' => 'bic',
        'notes' => 'notes',
    ];

    private const array YES = ['yes', 'y', 'true', '1', 'oui', 'o'];
    private const array NO = ['no', 'n', 'false', '0', 'non'];

    public function __construct(
        private FiscalPresets $presets,
        private ManageVendors $manage,
        private VendorRepository $vendors,
        private ExpenseCategoryDirectory $expenseCategories,
        private EntityManagerInterface $entityManager,
        private ConfirmStepUp $stepUp,
        private ResourceRules $rules,
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

    public function identityColumns(): array
    {
        return ['number'];
    }

    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        return RowIdentity::ofColumns($record, $this->identityColumns());
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [...$this->fixed(), ...$this->identifiers($company)]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        $number = $record->value('number') ?? throw new RowRejected('number', 'A vendor is found again by its number, so every row needs one.', 'value_required');
        $existing = $this->vendors->ofNumberInCompany($number, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw new RowRejected('number', 'A vendor already has this number. Import in "create and update" mode to update it.', 'already_exists');
        }
        // Every cell is read before anything is written, and every fault found is said at once (ruling 9).
        $faults = new RowFaults();
        $faults->read(fn () => $this->rules->hold(VendorResource::class, VendorResource::WRITE, self::cells($record)));

        $written = null;
        try {
            $input = $this->input($company, $record, $existing, $faults);
            $faults->stopIfAny();
            if (!$context->dryRun && $input->profile->changesBankDetailsOf($existing?->getProfile()) && (null === $actorUserId || !$this->stepUp->isRecent($actorUserId))) {
                throw new StepUpRequired();
            }
            if (null === $existing) {
                $written = $this->manage->create($company, $input, $actorUserId);

                return RowImported::Created;
            }
            // Rendered before the write, which changes the vendor in place.
            $categories = $this->categoryNames($company);
            $before = $this->inFile($existing, $context, $categories);
            $written = $this->manage->revise($company, $existing->getId(), $input, $actorUserId);

            return $notes->changedBetween($before, $this->inFile($written, $context, $categories)) ? RowImported::Updated : RowImported::Unchanged;
        } catch (InvalidVendor $refused) {
            throw new RowRejected(self::columnOf($refused->field), $refused->getMessage(), $refused->reason, $refused->params);
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
     * A refused cell is kept among the row's faults and leaves what the vendor holds in its place.
     *
     * @throws InvalidVendor
     */
    private function input(Company $company, ImportRecord $record, ?Vendor $current, RowFaults $faults): VendorInput
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
                $faults->read(static fn (): ?int => self::wholeNumber($record, 'payment_terms_days')) ?? $held?->paymentTermsDays,
                $record->value('notes') ?? $held?->notes,
                $faults->read(fn (): ?Uuid => $this->expenseCategoryId($company, $record, $held), $held?->defaultExpenseCategoryId),
            ),
            $faults->read(static fn (): ?bool => self::yesNo($record, 'active')) ?? $current?->isActive() ?? true,
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
            self::country($record->value('country_code')) ?? $held?->countryCode,
        );
        if ($address->isEmpty()) {
            return new PostalAddress();
        }

        return null !== $address->countryCode ? $address : new PostalAddress($address->line1, $address->line2, $address->postalCode, $address->city, $company->getCountryCode());
    }

    /**
     * The vendor as the file writes it, over the columns of this run (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5).
     *
     * @param array<string, string> $categories
     *
     * @return array<string, string> by column
     */
    private function inFile(Vendor $vendor, ImportContext $context, array $categories): array
    {
        $cells = [];
        foreach ($context->columns as $column) {
            $cells[$column] = VendorInFile::cell($vendor, $column, $categories);
        }

        return $cells;
    }

    /**
     * The company's expense categories by id, named as a row names them.
     *
     * @return array<string, string>
     */
    private function categoryNames(Company $company): array
    {
        return array_map(static fn (array $category): string => $category['name'], $this->expenseCategories->categoriesInCompany($company->getId()));
    }

    private function expenseCategoryId(Company $company, ImportRecord $record, ?VendorProfile $held): ?Uuid
    {
        $name = $record->value('expense_category');
        $heldId = $held?->defaultExpenseCategoryId;
        if (null === $name) {
            return $heldId;
        }
        // The vendor's own category under its own name stays, though archived since: what an export wrote comes back.
        if (null !== $heldId && $name === ($this->expenseCategories->categoriesInCompany($company->getId())[$heldId->toRfc4122()]['name'] ?? null)) {
            return $heldId;
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
            new ImportColumn('number', 'import.vendors.number', true, 'FRN-0001', 'import.vendors.number_note', code: true),
            new ImportColumn('name', 'import.vendors.name', true, 'Aciers du Sud'),
            new ImportColumn('legal_name', 'import.vendors.legal_name', false, 'Aciers du Sud SARL'),
            new ImportColumn('email', 'import.vendors.email', false, 'compta@aciers.tn'),
            new ImportColumn('phone', 'import.vendors.phone', false, '+216 71 000 000', code: true),
            new ImportColumn('website', 'import.vendors.website', false, 'https://aciers.tn'),
            new ImportColumn('line1', 'import.vendors.line1', false, '12 rue de la Fonderie'),
            new ImportColumn('line2', 'import.vendors.line2'),
            new ImportColumn('postal_code', 'import.vendors.postal_code', false, '2033', code: true),
            new ImportColumn('city', 'import.vendors.city', false, 'Megrine'),
            new ImportColumn('country_code', 'import.vendors.country_code', false, 'TN', 'import.country_note'),
            new ImportColumn('iban', 'import.vendors.iban', false, 'TN5910006035183598478831', code: true),
            new ImportColumn('bic', 'import.vendors.bic', false, 'BIATTNTT', code: true),
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
            $columns[] = new ImportColumn($identifier->key, $identifier->labelKey, false, $identifier->example, 'import.identifier_note', ImportHeading::FiscalLabel, code: true);
        }

        return $columns;
    }

    private static function columnOf(string $field): ?string
    {
        if (str_starts_with($field, 'identifiers.')) {
            return substr($field, \strlen('identifiers.'));
        }

        return self::COLUMN_OF[$field] ?? self::CELL_RULES[$field] ?? null;
    }

    /**
     * The cells the form's rules hold, by the property each fills; a country as the column holds it.
     *
     * @return array<string, array{column: string, value: ?string}>
     */
    private static function cells(ImportRecord $record): array
    {
        $cells = [];
        foreach (self::CELL_RULES as $property => $column) {
            $value = $record->value($column);
            $cells[$property] = ['column' => $column, 'value' => str_ends_with($property, 'ountryCode') ? self::country($value) : $value];
        }

        return $cells;
    }

    /** A country code in capitals, as a spreadsheet may well write it in small letters. */
    private static function country(?string $cell): ?string
    {
        return null === $cell ? null : mb_strtoupper($cell);
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
