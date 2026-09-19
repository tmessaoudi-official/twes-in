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
use App\Fiscal\Application\Preset\FiscalPresets;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportSubject;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Tenancy\Domain\Company;

/**
 * What a customer file holds, for one company (docs/SPEC.md § 7, 2026-09-17).
 *
 * Two groups of columns are the company's own and cannot be written down here: the registration numbers its fiscal
 * preset asks for — a SIRET and a VAT number in France, something else elsewhere — and the custom fields it has
 * defined for a customer. Both are read per company, which is why a template is generated rather than shipped.
 *
 * The declaration lives in Infrastructure, beside this module's settings and manifest declarations, because it reads
 * the fiscal preset and the custom fields of other contexts.
 */
final readonly class CustomerImport implements DeclaresImport
{
    public const string KEY = 'customers';

    /** A custom field's key is the company's own, so it is prefixed to keep it out of the fixed columns' namespace. */
    public const string CUSTOM_PREFIX = 'custom.';

    public function __construct(
        private FiscalPresets $presets,
        private CustomFieldDefinitionRepository $customFields,
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

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [...$this->fixed(), ...$this->identifiers($company), ...$this->custom($company)]);
    }

    /**
     * The columns every company has. `number` is required because a customer is found again by it on a second import:
     * a file without one can only ever create, never update, and says so in its preview.
     *
     * @return list<ImportColumn>
     */
    private function fixed(): array
    {
        return [
            new ImportColumn('number', 'import.customers.number', true, 'CLI-0001', 'import.customers.number_note'),
            new ImportColumn('kind', 'import.customers.kind', true, 'company', 'import.customers.kind_note'),
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
            $columns[] = new ImportColumn($identifier->key, $identifier->labelKey, false, null, 'import.identifier_note');
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
            );
        }

        return $columns;
    }

    private static function exampleOf(CustomFieldDefinition $field): ?string
    {
        $choices = $field->getChoices();

        return [] === $choices ? null : (string) reset($choices);
    }
}
