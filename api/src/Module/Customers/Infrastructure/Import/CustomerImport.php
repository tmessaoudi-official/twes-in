<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Import;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\TaxComponentRepository;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowRejected;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\CustomerNumberTaken;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroupRepository;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\InvalidCustomer;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a customer file holds, for one company, and what one of its rows does (docs/SPEC.md § 7, 2026-09-17).
 *
 * Two groups of columns are the company's own and cannot be written down here: the registration numbers its fiscal
 * preset asks for — a SIRET and a VAT number in France, something else elsewhere — and the custom fields it has
 * defined for a customer. Both are read per company, which is why a template is generated rather than shipped.
 *
 * A row is written through ManageCustomers, the use case the customer form uses, so a file is held to every rule a
 * person is: the preset's regimes and registration numbers, the company's groups, taxes and custom fields. A row whose
 * number is a customer's already updates it in upsert mode, from the cells the row fills in only: a blank cell keeps
 * what is there (docs/SPEC.md § 7, 2026-09-19).
 *
 * The declaration lives in Infrastructure, beside this module's settings and manifest declarations, because it reads
 * the fiscal preset and the custom fields of other contexts.
 */
final readonly class CustomerImport implements DeclaresImport
{
    public const string KEY = 'customers';

    /** A custom field's key is the company's own, so it is prefixed to keep it out of the fixed columns' namespace. */
    public const string CUSTOM_PREFIX = 'custom.';

    /** The column each field a refusal names is read from. */
    private const array COLUMN_OF = [
        'number' => 'number',
        'name' => 'name',
        'taxRegime' => 'tax_regime_code',
        'customerGroupId' => 'customer_group',
        'defaultTaxComponentIds' => 'default_tax_codes',
        'defaultDiscountRate' => 'default_discount_rate',
    ];

    private const array YES = ['yes', 'y', 'true', '1', 'oui', 'o'];
    private const array NO = ['no', 'n', 'false', '0', 'non'];

    public function __construct(
        private FiscalPresets $presets,
        private CustomFieldDefinitionRepository $customFields,
        private ManageCustomers $manage,
        private CustomerRepository $customers,
        private CustomerGroupRepository $groups,
        private TaxComponentRepository $taxes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return CustomerPermission::WRITE;
    }

    public function module(): string
    {
        return CustomersModule::KEY;
    }

    public function identityColumns(): array
    {
        return ['number'];
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [...$this->fixed(), ...$this->identifiers($company), ...$this->custom($company)]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported
    {
        $number = $record->value('number') ?? throw new RowRejected('number', 'A customer is found again by its number, so every row needs one.', 'value_required');
        $existing = $this->customers->ofNumberInCompany($number, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw new RowRejected('number', 'A customer already has this number. Import in "create and update" mode to update it.', 'already_exists');
        }

        $written = null;
        try {
            if (null === $existing) {
                $written = $this->manage->create($company, $this->input($company, $record, null), $actorUserId);

                return RowImported::Created;
            }
            $written = $this->manage->revise($company, $existing->getId(), $this->input($company, $record, $existing), $actorUserId);

            return RowImported::Updated;
        } catch (InvalidCustomer $refused) {
            throw new RowRejected(self::columnOf($refused->field), $refused->getMessage(), $refused->reason, $refused->params);
        } catch (CustomerNumberTaken) {
            throw new RowRejected('number', 'A customer already has this number.', 'already_exists');
        } finally {
            // Doctrine's batch processing: every flush walks every managed entity, so a row's customer stays out of
            // the unit of work once written, or a file costs the square of its length. Nothing reads it back here.
            foreach ([$existing, $written] as $customer) {
                if (null !== $customer) {
                    $this->entityManager->detach($customer);
                }
            }
        }
    }

    /**
     * The customer the row describes: every cell it fills in, and for an existing customer what it already holds
     * wherever the row leaves a cell blank.
     *
     * @throws InvalidCustomer
     * @throws RowRejected
     */
    private function input(Company $company, ImportRecord $record, ?Customer $current): CustomerInput
    {
        $profile = $current?->getProfile();
        $kind = $record->value('kind');
        $identifiers = $profile->identifiers ?? [];
        foreach ($this->presets->get($company->getFiscalPreset())->identifiers as $identifier) {
            $identifiers[$identifier->key] = $record->value($identifier->key) ?? $identifiers[$identifier->key] ?? null;
        }

        return new CustomerInput(
            $record->value('number') ?? '',
            new CustomerProfile(
                null === $kind ? ($profile->kind ?? CustomerKind::Company) : (CustomerKind::tryFrom(strtolower($kind)) ?? throw new RowRejected('kind', 'A customer is a "company" or an "individual".', 'not_one_of', ['choices' => implode(', ', array_column(CustomerKind::cases(), 'value'))])),
                $record->value('name') ?? $profile->name ?? '',
                $record->value('legal_name') ?? $profile?->legalName,
                $identifiers,
                $record->value('email') ?? $profile?->email,
                $record->value('phone') ?? $profile?->phone,
                $record->value('website') ?? $profile?->website,
                $this->address($company, $record, 'billing', $profile?->billingAddress) ?? new PostalAddress(countryCode: $company->getCountryCode()),
                $this->address($company, $record, 'shipping', $profile?->shippingAddress),
                self::decimal($record->value('default_discount_rate')) ?? $profile?->defaultDiscountRate,
                $record->value('notes') ?? $profile?->notes,
            ),
            $this->groupId($company, $record, $current),
            $record->value('tax_regime_code') ?? $current?->getTaxRegime()->getCode() ?? 'standard',
            $this->taxIds($company, $record, $current),
            self::yesNo($record, 'active') ?? $current?->isActive() ?? true,
            $this->customValues($company, $record, $current),
        );
    }

    /** The address the row fills in over the one held, in the company's country when neither names one. */
    private function address(Company $company, ImportRecord $record, string $prefix, ?PostalAddress $held): ?PostalAddress
    {
        $address = new PostalAddress(
            $record->value($prefix.'_line1') ?? $held?->line1,
            $record->value($prefix.'_line2') ?? $held?->line2,
            $record->value($prefix.'_postal_code') ?? $held?->postalCode,
            $record->value($prefix.'_city') ?? $held?->city,
            $record->value($prefix.'_country_code') ?? $held?->countryCode,
        );
        if ($address->isEmpty()) {
            return null;
        }

        return null !== $address->countryCode ? $address : new PostalAddress($address->line1, $address->line2, $address->postalCode, $address->city, $company->getCountryCode());
    }

    private function groupId(Company $company, ImportRecord $record, ?Customer $current): ?Uuid
    {
        $name = $record->value('customer_group');
        if (null === $name) {
            return $current?->getGroup()?->getId();
        }

        return $this->groups->ofNameInCompany($name, $company->getId())?->getId()
            ?? throw new RowRejected('customer_group', \sprintf('The company has no customer group named "%s". Create it first.', $name), 'unknown_group', ['name' => $name]);
    }

    /** @return list<Uuid> */
    private function taxIds(Company $company, ImportRecord $record, ?Customer $current): array
    {
        $codes = $record->value('default_tax_codes');
        if (null === $codes) {
            return array_map(Uuid::fromString(...), $current?->getDefaultTaxComponentIds() ?? []);
        }

        $ids = [];
        foreach (preg_split('/[\s;,]+/', $codes, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            $ids[] = $this->taxes->ofCodeInCompany($code, $company->getId())?->getId()
                ?? throw new RowRejected('default_tax_codes', \sprintf('The company has no tax coded "%s".', $code), 'unknown_tax_code', ['code' => $code]);
        }

        return $ids;
    }

    /**
     * The row's custom field cells, typed as the form sends them, over the values held.
     *
     * @return array<string, string|int|float|bool>
     */
    private function customValues(Company $company, ImportRecord $record, ?Customer $current): array
    {
        $values = $current?->getCustomFields() ?? [];
        foreach ($this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Customer) as $field) {
            $column = self::CUSTOM_PREFIX.$field->getKey();
            $cell = $record->value($column);
            if (null === $cell || !$field->isActive()) {
                continue;
            }
            $values[$field->getKey()] = match ($field->getType()) {
                CustomFieldType::Number => self::number($cell) ?? throw new RowRejected($column, 'A number.', 'not_a_number'),
                CustomFieldType::Bool => self::yesNo($record, $column) ?? throw new RowRejected($column, 'Yes or no.', 'not_yes_or_no'),
                default => $cell,
            };
        }

        return $values;
    }

    /**
     * The columns every company has. `number` is required because a customer is found again by it on a second import.
     *
     * @return list<ImportColumn>
     */
    private function fixed(): array
    {
        return [
            new ImportColumn('number', 'import.customers.number', true, 'CLI-0001', 'import.customers.number_note'),
            new ImportColumn('kind', 'import.customers.kind', false, 'company', 'import.customers.kind_note'),
            new ImportColumn('name', 'import.customers.name', true, 'Boulangerie Mercier'),
            new ImportColumn('legal_name', 'import.customers.legal_name', false, 'Mercier SARL'),
            new ImportColumn('email', 'import.customers.email', false, 'contact@mercier.fr'),
            new ImportColumn('phone', 'import.customers.phone', false, '+33 1 23 45 67 89'),
            new ImportColumn('website', 'import.customers.website', false, 'https://mercier.fr'),
            new ImportColumn('billing_line1', 'import.customers.billing_line1', false, '12 rue des Lilas'),
            new ImportColumn('billing_line2', 'import.customers.billing_line2', false, 'Bâtiment C'),
            new ImportColumn('billing_postal_code', 'import.customers.billing_postal_code', false, '75011'),
            new ImportColumn('billing_city', 'import.customers.billing_city', false, 'Paris'),
            new ImportColumn('billing_country_code', 'import.customers.billing_country_code', false, 'FR', 'import.country_note'),
            new ImportColumn('shipping_line1', 'import.customers.shipping_line1', false, null, 'import.customers.shipping_note'),
            new ImportColumn('shipping_line2', 'import.customers.shipping_line2'),
            new ImportColumn('shipping_postal_code', 'import.customers.shipping_postal_code'),
            new ImportColumn('shipping_city', 'import.customers.shipping_city'),
            new ImportColumn('shipping_country_code', 'import.customers.shipping_country_code', false, null, 'import.country_note'),
            new ImportColumn('customer_group', 'import.customers.group', false, null, 'import.customers.group_note'),
            new ImportColumn('tax_regime_code', 'import.customers.tax_regime', false, null, 'import.customers.tax_regime_note'),
            new ImportColumn('default_tax_codes', 'import.customers.default_taxes', false, null, 'import.customers.default_taxes_note'),
            new ImportColumn('default_discount_rate', 'import.customers.discount', false, '5.000', 'import.rate_note'),
            new ImportColumn('notes', 'import.customers.notes'),
            new ImportColumn('active', 'import.customers.active', false, 'yes', 'import.boolean_note'),
        ];
    }

    /**
     * The registration numbers this company's preset asks for, each under its own key. The heading is the preset's own
     * label, so a French company reads "SIRET" where a Tunisian one reads its own.
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

    /**
     * This company's own custom fields for a customer, active ones only: a field switched off is not asked for in a
     * file a person is about to fill in.
     *
     * @return list<ImportColumn>
     */
    private function custom(Company $company): array
    {
        $columns = [];
        foreach ($this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Customer) as $field) {
            if (!$field->isActive()) {
                continue;
            }

            $columns[] = new ImportColumn(
                self::CUSTOM_PREFIX.$field->getKey(),
                $field->getLabel(),
                $field->isRequired(),
                self::exampleOf($field),
                headingIs: ImportHeading::Label,
            );
        }

        return $columns;
    }

    private static function exampleOf(CustomFieldDefinition $field): ?string
    {
        $choices = $field->getChoices();

        return [] === $choices ? null : (string) reset($choices);
    }

    private static function columnOf(string $field): ?string
    {
        if (str_starts_with($field, 'identifiers.')) {
            return substr($field, \strlen('identifiers.'));
        }
        if (str_starts_with($field, 'customFields.')) {
            return self::CUSTOM_PREFIX.substr($field, \strlen('customFields.'));
        }

        return self::COLUMN_OF[$field] ?? null;
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

    /** A decimal written with a point or, as a French or Tunisian spreadsheet writes it, a comma. */
    private static function decimal(?string $cell): ?string
    {
        return null === $cell ? null : str_replace(',', '.', $cell);
    }

    private static function number(string $cell): int|float|null
    {
        $normalised = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $cell);
        if (!is_numeric($normalised)) {
            return null;
        }

        return 1 === preg_match('/^-?\d+$/', $normalised) ? (int) $normalised : (float) $normalised;
    }
}
