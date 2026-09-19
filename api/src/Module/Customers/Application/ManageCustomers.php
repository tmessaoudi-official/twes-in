<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldValues;
use App\CustomFields\Domain\InvalidCustomFieldValue;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerGroupRepository;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\CustomerSearch;
use App\Module\Customers\Domain\InvalidCustomer;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's customers. The company's fiscal preset decides the regimes a customer may be given and the registration
 * numbers a domestic business customer must carry; a customer billed abroad carries none of them. Default taxes are
 * the company's active ones that the customer's regime charges. Custom field values are checked against the company's
 * fields for customers. Audited with the names of the fields a revision
 * changed, never their values: a customer may be a private person.
 */
final readonly class ManageCustomers
{
    public const string ENTITY_TYPE = 'customer';
    public const string CREATED = 'customer.created';
    public const string REVISED = 'customer.revised';

    public function __construct(
        private CustomerRepository $customers,
        private CustomerGroupRepository $groups,
        private CustomerTaxRegimeRepository $regimes,
        private TaxComponentRepository $taxes,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private CustomFieldDefinitionRepository $customFields,
        private Transactions $transactions,
    ) {
    }

    /** @return list<Customer> */
    public function list(Company $company): array
    {
        return $this->customers->ofCompany($company->getId());
    }

    /** @return Page<Customer> */
    public function search(Company $company, CustomerSearch $search, PageRequest $page): Page
    {
        return $this->customers->search($company->getId(), $search, $page);
    }

    /** @throws CustomerNotFound */
    public function get(Company $company, Uuid $id): Customer
    {
        return $this->customers->ofIdInCompany($id, $company->getId()) ?? throw new CustomerNotFound();
    }

    /**
     * @throws CustomerNumberTaken
     * @throws InvalidCustomer
     */
    public function create(Company $company, CustomerInput $input, ?Uuid $actorUserId): Customer
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): Customer {
            if (null !== $this->customers->ofNumberInCompany(trim($input->number), $company->getId())) {
                throw new CustomerNumberTaken();
            }
            [$group, $regime] = $this->checked($company, $input, null);
            $values = $this->customFieldValues($company, $input, null);
            $customer = Customer::create($company, $input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, $this->clock->now());
            $customer->reviseCustomFields($values, $this->clock->now());
            if (!$input->isActive) {
                $customer->revise($input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, false, $this->clock->now());
            }
            $this->customers->save($customer);
            $this->record($company, $customer->getId(), self::CREATED, [], $actorUserId);

            return $customer;
        });
    }

    /**
     * @throws CustomerNotFound
     * @throws CustomerNumberTaken
     * @throws InvalidCustomer
     */
    public function revise(Company $company, Uuid $id, CustomerInput $input, ?Uuid $actorUserId): Customer
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): Customer {
            $customer = $this->get($company, $id);
            $holder = $this->customers->ofNumberInCompany(trim($input->number), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($customer->getId())) {
                throw new CustomerNumberTaken();
            }
            [$group, $regime] = $this->checked($company, $input, $customer);
            $values = $this->customFieldValues($company, $input, $customer);

            $changed = $customer->revise($input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, $input->isActive, $this->clock->now());
            $changed = [...$changed, ...$customer->reviseCustomFields($values, $this->clock->now())];
            if ([] !== $changed) {
                $this->customers->save($customer);
                $this->record($company, $customer->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $customer;
        });
    }

    /**
     * What the input names, found and checked against the company and its preset.
     *
     * @return array{CustomerGroup|null, CustomerTaxRegime}
     */
    private function checked(Company $company, CustomerInput $input, ?Customer $current): array
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        $code = trim($input->taxRegimeCode);
        $offered = \in_array($code, array_map(static fn ($regime): string => $regime->code, $preset->customerTaxRegimes), true);
        // A regime the preset stopped listing stays with the customers who already have it.
        $kept = null !== $current && $current->getTaxRegime()->getCode() === $code;
        $regime = $offered || $kept ? $this->regimes->ofPresetAndCode($company->getFiscalPreset(), $code) : null;
        if (null === $regime) {
            throw new InvalidCustomer('taxRegime', \sprintf('The %s preset offers customers no regime "%s".', $preset->country, $code), 'unknown_tax_regime', ['code' => $code]);
        }

        $group = null;
        if (null !== $input->customerGroupId) {
            $group = $this->groups->ofIdInCompany($input->customerGroupId, $company->getId())
                ?? throw new InvalidCustomer('customerGroupId', 'No customer group of this company has this id.', 'unknown_group_id');
        }

        foreach ($input->defaultTaxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId());
            if (null === $tax || !$tax->isActive()) {
                throw new InvalidCustomer('defaultTaxComponentIds', \sprintf('No active tax of this company has the id %s.', $taxId->toRfc4122()), 'unknown_tax', ['id' => $taxId->toRfc4122()]);
            }
            if (\in_array($tax->getFamily(), $regime->getExcludedFamilies(), true)) {
                throw new InvalidCustomer('defaultTaxComponentIds', \sprintf('The %s regime does not charge %s.', $regime->getCode(), $tax->getCode()), 'regime_excludes_tax', ['regime' => $regime->getCode(), 'tax' => $tax->getCode()]);
            }
        }

        $country = $input->profile->billingAddress->countryCode;
        $domesticBusiness = CustomerKind::Company === $input->profile->kind && (null === $country || $country === $company->getCountryCode());
        $refusal = IdentifierRules::refusal($preset, $input->profile->identifiers, $domesticBusiness ? IdentifierRules::BUSINESS_CUSTOMER : '');
        if (null !== $refusal) {
            throw new InvalidCustomer($refusal->field, $refusal->message, $refusal->reason, $refusal->params);
        }

        return [$group, $regime];
    }

    /** @return array<string, string|int|float|bool> */
    private function customFieldValues(Company $company, CustomerInput $input, ?Customer $current): array
    {
        $rules = array_map(static fn (CustomFieldDefinition $field) => $field->rule(), $this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Customer));
        try {
            return CustomFieldValues::checked($rules, $input->customFields, $current?->getCustomFields() ?? []);
        } catch (InvalidCustomFieldValue $refused) {
            throw new InvalidCustomer($refused->field, $refused->getMessage(), $refused->reason, $refused->params);
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $customerId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $customerId, $action, $actorUserId, $changes, $company->getId()));
    }
}
